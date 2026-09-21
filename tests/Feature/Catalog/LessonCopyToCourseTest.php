<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Filament\Resources\LessonResource\Pages\ListLessons;
use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Копировать в курс…» в /admin/lessons.
 *
 * Боевой повод (21-09-2026): эфир клуба лежал уроком курса вебинара «Грамматика
 * санскрита (2026)», и метку `club_efir` поставили ему прямо там — фильтр
 * «Курс = Клуб» его не видит, подписчик тоже. Правильный ход — копия в курсе
 * «Клуб» с меткой эфира, а оригинал вебинара остаётся покупным уроком.
 */
class LessonCopyToCourseTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::ADMIN]));
    }

    private function webinarLesson(Course $webinar, array $overrides = []): Lesson
    {
        return Lesson::factory()->for($webinar)->create(array_merge([
            'title' => 'Грамматика санскрита — эфир 1',
            'slug' => 'grammatika-efir-1',
            'block_number' => 1,
            'is_published' => true,
            'youtube_url' => 'https://www.youtube.com/embed/efir1',
            'rutube_url' => 'https://rutube.ru/play/embed/efir1/',
            'recording_kind' => 'course_lesson',
        ], $overrides));
    }

    /** @test */
    public function single_lesson_is_copied_into_the_club_with_efir_tag_and_original_untouched(): void
    {
        $webinar = Course::factory()->create(['title' => 'Вебинар «Грамматика санскрита» (2026)']);
        $club = Course::factory()->create(['title' => 'Клуб']);
        $existing = Lesson::factory()->for($club)->create(['sort_order' => 7, 'group_id' => null]);
        $lesson = $this->webinarLesson($webinar, ['is_preview' => true, 'homework_enabled' => true, 'textbook_lesson' => 5]);

        $this->actingAsAdmin();

        Livewire::test(ListLessons::class)
            ->callTableAction('copyToCourse', $lesson, data: [
                'target_course_id' => $club->id,
                'recording_kind' => 'club_efir',
            ])
            ->assertHasNoTableActionErrors();

        $copy = Lesson::query()->where('course_id', $club->id)->whereKeyNot($existing->id)->sole();

        $this->assertSame('Грамматика санскрита — эфир 1', $copy->title);
        $this->assertSame('https://www.youtube.com/embed/efir1', $copy->youtube_url);
        $this->assertSame('https://rutube.ru/play/embed/efir1/', $copy->rutube_url);
        $this->assertSame('club_efir', $copy->recording_kind);
        $this->assertTrue($copy->isClubStreamRecording());
        $this->assertSame(8, $copy->sort_order, 'копия встаёт в конец курса-приёмника');
        $this->assertSame('grammatika-efir-1-k'.$club->id, $copy->slug);
        $this->assertFalse($copy->is_preview, 'пробный урок — один на курс, не переносится');
        $this->assertFalse($copy->homework_enabled);
        $this->assertNull($copy->textbook_lesson, 'номер занятия = вход в автооткрытие ДЗ, не переносится');
        $this->assertNotNull($copy->recording_attached_at);

        $lesson->refresh();
        $this->assertSame($webinar->id, (int) $lesson->course_id);
        $this->assertSame('course_lesson', $lesson->recording_kind);
        $this->assertTrue($lesson->is_preview);
    }

    /** @test */
    public function bulk_copy_keeps_order_and_appends_after_target_lessons_of_the_chosen_group(): void
    {
        $source = Course::factory()->create();
        $target = Course::factory()->create();
        $group = Group::factory()->create();
        $target->groups()->attach($group->id);
        Lesson::factory()->for($target)->create(['group_id' => $group->id, 'sort_order' => 3]);
        // Урок другой группы не должен сдвигать нумерацию выбранной.
        Lesson::factory()->for($target)->create(['group_id' => null, 'sort_order' => 40]);

        $lessons = collect([3, 1, 2])->map(fn (int $i) => Lesson::factory()->for($source)->create([
            'title' => "Урок {$i}",
            'block_number' => 1,
            'sort_order' => $i,
            'recording_kind' => 'course_lesson',
        ]));

        $this->actingAsAdmin();

        Livewire::test(ListLessons::class)
            ->callTableBulkAction('copyToCourse', $lessons, data: [
                'target_course_id' => $target->id,
                'group_id' => $group->id,
                'recording_kind' => 'keep',
            ])
            ->assertHasNoTableBulkActionErrors();

        $copies = Lesson::query()->where('course_id', $target->id)->where('group_id', $group->id)
            ->where('sort_order', '>', 3)->orderBy('sort_order')->get();

        $this->assertSame(['Урок 1', 'Урок 2', 'Урок 3'], $copies->pluck('title')->all());
        $this->assertSame([4, 5, 6], $copies->pluck('sort_order')->all());
        $this->assertSame(['course_lesson'], $copies->pluck('recording_kind')->unique()->values()->all());
        $this->assertSame(3, Lesson::query()->where('course_id', $source->id)->count(), 'оригиналы на месте');
    }

    /** @test */
    public function copy_does_not_trigger_clip_extraction_or_carry_the_transcript(): void
    {
        config(['features.content_from_lectures' => true, 'features.clip_marketing' => true]);
        Queue::fake();

        $source = Course::factory()->create();
        $target = Course::factory()->create();
        $lesson = $this->webinarLesson($source, ['transcript_file' => 'transcripts/efir1.txt']);
        // Оригинал со стенограммой законно заказал нарезку — считаем только копию.
        Queue::fake();

        $this->actingAsAdmin();

        Livewire::test(ListLessons::class)
            ->callTableAction('copyToCourse', $lesson, data: [
                'target_course_id' => $target->id,
                'recording_kind' => 'keep',
            ]);

        $copy = Lesson::query()->where('course_id', $target->id)->sole();
        $this->assertNull($copy->transcript_file);
        Queue::assertNotPushed(DispatchLectureClipExtractionJob::class);
    }

    /** @test */
    public function block_override_places_the_copy_into_the_chosen_target_block(): void
    {
        $source = Course::factory()->create();
        $target = Course::factory()->create();
        CourseBlock::factory()->for($target)->create(['number' => 2]);
        $lesson = $this->webinarLesson($source, ['block_number' => 1]);

        $this->actingAsAdmin();

        Livewire::test(ListLessons::class)
            ->callTableAction('copyToCourse', $lesson, data: [
                'target_course_id' => $target->id,
                'block_number' => 2,
                'recording_kind' => 'keep',
            ]);

        $this->assertSame(2, Lesson::query()->where('course_id', $target->id)->sole()->block_number);
    }

    /** @test */
    public function refusals_write_nothing(): void
    {
        $source = Course::factory()->create();
        $withBlocks = Course::factory()->create();
        CourseBlock::factory()->for($withBlocks)->create(['number' => 1]);
        $foreignGroup = Group::factory()->create();
        $lesson = $this->webinarLesson($source, ['block_number' => 3]);

        $this->actingAsAdmin();
        $before = Lesson::query()->count();

        // Приёмник = свой же курс.
        Livewire::test(ListLessons::class)
            ->callTableAction('copyToCourse', $lesson, data: ['target_course_id' => $source->id, 'recording_kind' => 'keep']);
        // У приёмника нет блока 3 — купившие не увидели бы копию.
        Livewire::test(ListLessons::class)
            ->callTableAction('copyToCourse', $lesson, data: ['target_course_id' => $withBlocks->id, 'recording_kind' => 'keep']);
        // Группа не из курса-приёмника.
        Livewire::test(ListLessons::class)
            ->callTableAction('copyToCourse', $lesson, data: [
                'target_course_id' => $withBlocks->id,
                'block_number' => 1,
                'group_id' => $foreignGroup->id,
                'recording_kind' => 'keep',
            ]);

        $this->assertSame($before, Lesson::query()->count());
    }

    /** @test */
    public function teacher_does_not_see_the_copy_actions(): void
    {
        $teacher = Teacher::create(['name' => 'Препод', 'email' => 'copy-teacher@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lesson = Lesson::factory()->for($course)->create();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]));

        Livewire::test(ListLessons::class)
            ->assertTableActionHidden('copyToCourse', $lesson)
            ->assertTableBulkActionHidden('copyToCourse');
    }
}
