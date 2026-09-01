<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Lesson;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Перенос записей на курс-запись (H3823).
 *
 * Боевая форма, ради которой команда написана: курс 327 «Йога-сутры Патанджали
 * (1 поток, 2025) в записи» — 4 блока, 5 активных тарифов, 129 оплат и НОЛЬ
 * уроков; все шестнадцать записей лежат на уроках живого курса 396. Купившие
 * держат `block_1` (40), `block_2` (33), `block_3` (28), `block_4` (28) —
 * `full` не купил никто, поэтому проверка «доступ есть» обязана идти именно по
 * поблочным ключам, а не по `full`.
 *
 * Главное, что тут пришито: перенесённый урок открывается ТЕМ ЖЕ ключом,
 * который купил студент. Ключ выводится из `block_number`
 * ({@see Lesson::unlockingKeys()}), поэтому дословный перенос номера блока —
 * не косметика, а весь механизм доступа.
 */
class MirrorRecordingLessonsTest extends TestCase
{
    use RefreshDatabase;

    private function courseWithBlocks(string $title, string $slug, int $blocks): Course
    {
        $course = Course::factory()->create(['title' => $title, 'slug' => $slug]);

        for ($n = 1; $n <= $blocks; $n++) {
            CourseBlock::factory()->for($course)->create(['number' => $n]);
        }

        return $course;
    }

    /** Живой поток: 16 уроков по четырём блокам, у каждого ссылки на запись. */
    private function liveCourseWithRecordings(): Course
    {
        $course = $this->courseWithBlocks('Йога-сутры Патанджали (1 поток, 2025)', 'ys-live-h3823-test', 4);

        for ($i = 1; $i <= 16; $i++) {
            Lesson::factory()->for($course)->create([
                'title' => "А.В. Парибок. «Йога-сутры» Патанджали ({$i}/16)",
                'slug' => "ys-live-urok-{$i}-test",
                'block_number' => (int) ceil($i / 4),
                'sort_order' => $i,
                'is_published' => true,
                'homework_enabled' => false,
                'youtube_url' => "https://www.youtube.com/embed/vid{$i}",
                'rutube_url' => "https://rutube.ru/play/embed/rt{$i}/",
            ]);
        }

        return $course;
    }

    /** Курс-запись: блоки и тарифы есть, уроков нет — боевая форма 327. */
    private function recordingCourse(): Course
    {
        $course = $this->courseWithBlocks('Йога-сутры Патанджали (1 поток, 2025) в записи', 'ys-zapis-h3823-test', 4);

        Tariff::factory()->for($course)->create(['is_active' => true]);

        return $course;
    }

    /** @test */
    public function a_dry_run_writes_nothing(): void
    {
        $live = $this->liveCourseWithRecordings();
        $recording = $this->recordingCourse();

        $this->assertSame(0, Artisan::call('catalog:mirror-recording-lessons', [
            'source' => $live->id, 'target' => $recording->id,
        ]));

        $this->assertSame(0, $recording->lessons()->count(), 'без --apply не создаётся ни одного урока');
        $this->assertStringContainsString('Сухой прогон', Artisan::output());
    }

    /** @test */
    public function apply_mirrors_every_recording_onto_the_recording_course(): void
    {
        $live = $this->liveCourseWithRecordings();
        $recording = $this->recordingCourse();

        Artisan::call('catalog:mirror-recording-lessons', [
            'source' => $live->id, 'target' => $recording->id, '--apply' => true,
        ]);

        $this->assertSame(16, $recording->lessons()->count());

        $mirrored = $recording->lessons()->orderBy('sort_order')->get();
        $this->assertSame(
            range(1, 16),
            $mirrored->pluck('sort_order')->map(fn ($n) => (int) $n)->all(),
        );
        $this->assertSame(
            [1, 1, 1, 1, 2, 2, 2, 2, 3, 3, 3, 3, 4, 4, 4, 4],
            $mirrored->pluck('block_number')->map(fn ($n) => (int) $n)->all(),
            'номер блока перенесён дословно — из него выводится ключ доступа',
        );

        $first = $mirrored->first();
        $this->assertSame('https://www.youtube.com/embed/vid1', $first->youtube_url);
        $this->assertSame('https://rutube.ru/play/embed/rt1/', $first->rutube_url);
        $this->assertTrue((bool) $first->is_published);
    }

    /** @test */
    public function a_mirrored_lesson_is_unlocked_by_the_block_key_the_student_actually_bought(): void
    {
        // Смысл всей задачи: купивший block_2 обязан открыть второй блок записи.
        // `full` в боевых оплатах курса 327 не встречается ни разу.
        $live = $this->liveCourseWithRecordings();
        $recording = $this->recordingCourse();

        Artisan::call('catalog:mirror-recording-lessons', [
            'source' => $live->id, 'target' => $recording->id, '--apply' => true,
        ]);

        $blockTwo = $recording->lessons()->where('block_number', 2)->orderBy('sort_order')->first();
        $this->assertNotNull($blockTwo);

        $keys = (array) $blockTwo->unlockingKeys();
        $this->assertContains('block_2', $keys, 'купленный block_2 открывает второй блок записи');
        $this->assertNotContains('block_1', $keys, 'block_1 не должен открывать чужой блок');
    }

    /** @test */
    public function a_second_run_creates_no_duplicates(): void
    {
        $live = $this->liveCourseWithRecordings();
        $recording = $this->recordingCourse();

        $args = ['source' => $live->id, 'target' => $recording->id, '--apply' => true];

        Artisan::call('catalog:mirror-recording-lessons', $args);
        Artisan::call('catalog:mirror-recording-lessons', $args);

        $this->assertSame(16, $recording->lessons()->count(), 'повторный прогон идемпотентен');
    }

    /** @test */
    public function it_refuses_when_the_target_lacks_a_block_the_lessons_need(): void
    {
        // Иначе перенесённый урок получил бы ключ block_4, которого у цели нет
        // ни в блоках, ни в тарифах — и остался бы недостижим для купивших.
        $live = $this->liveCourseWithRecordings();
        $recording = $this->courseWithBlocks('Йога-сутры в записи (мало блоков)', 'ys-zapis-short-test', 2);

        $this->assertSame(1, Artisan::call('catalog:mirror-recording-lessons', [
            'source' => $live->id, 'target' => $recording->id, '--apply' => true,
        ]));

        $this->assertSame(0, $recording->lessons()->count());
        $this->assertStringContainsString('нет блоков 3, 4', Artisan::output());
    }

    /** @test */
    public function it_touches_neither_tariffs_nor_visibility(): void
    {
        // Прямое эхо инцидента 31-08-2026 (H3812): та правка погасила пять живых
        // тарифов. Эта команда пишет только в lessons.
        $live = $this->liveCourseWithRecordings();
        $recording = $this->recordingCourse();
        $recording->update(['is_visible' => false]);

        Artisan::call('catalog:mirror-recording-lessons', [
            'source' => $live->id, 'target' => $recording->id, '--apply' => true,
        ]);

        $recording->refresh();
        $this->assertSame(1, $recording->tariffs()->where('is_active', true)->count());
        $this->assertFalse((bool) $recording->is_visible, 'видимость не тронута');
        $this->assertSame(16, $live->lessons()->count(), 'источник не тронут');
    }

    /** @test */
    public function mirrored_lessons_carry_no_group_and_no_homework(): void
    {
        $live = $this->liveCourseWithRecordings();
        $recording = $this->recordingCourse();

        Artisan::call('catalog:mirror-recording-lessons', [
            'source' => $live->id, 'target' => $recording->id, '--apply' => true,
        ]);

        foreach ($recording->lessons()->get() as $lesson) {
            $this->assertNull($lesson->group_id, 'у записи своя группа — привязку живого потока не тащим');
            $this->assertFalse((bool) $lesson->homework_enabled, 'у курса-записи нет проверяющего домашних работ');
        }
    }
}
