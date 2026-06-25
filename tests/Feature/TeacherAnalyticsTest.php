<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\TeacherAnalytics as TeacherAnalyticsPage;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\Teacher;
use App\Models\User;
use App\Services\TeacherAnalytics;
use App\Support\Roles;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TeacherAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function recordView(int $userId, Lesson $lesson, Course $course, bool $completed, ?string $firstOpened = null): void
    {
        LessonView::create([
            'user_id' => $userId,
            'lesson_id' => $lesson->id,
            'course_id' => $course->id,
            'first_opened_at' => $firstOpened ?? now(),
            'last_opened_at' => now(),
            'open_count' => 1,
            'is_completed' => $completed,
        ]);
    }

    /** @test */
    public function course_ids_are_scoped_to_the_teacher(): void
    {
        $teacherA = Teacher::create(['name' => 'A', 'email' => 'a@example.test']);
        $teacherB = Teacher::create(['name' => 'B', 'email' => 'b@example.test']);
        $courseA = Course::factory()->create(['teacher_id' => $teacherA->id]);
        $courseB = Course::factory()->create(['teacher_id' => $teacherB->id]);

        $ids = TeacherAnalytics::for($teacherA->id)->courseIds();

        $this->assertTrue($ids->contains($courseA->id));
        $this->assertFalse($ids->contains($courseB->id));
    }

    /** @test */
    public function funnel_and_progress_are_computed_from_lesson_views(): void
    {
        $teacher = Teacher::create(['name' => 'T', 'email' => 't@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lessons = Lesson::factory()->count(4)->for($course)->create();
        $s1 = User::factory()->create();
        $s2 = User::factory()->create();

        // s1: открыл 2 урока, оба пройдены (50% из 4).
        $this->recordView($s1->id, $lessons[0], $course, true);
        $this->recordView($s1->id, $lessons[1], $course, true);
        // s2: открыл 1 урок, не пройден (0%).
        $this->recordView($s2->id, $lessons[0], $course, false);

        $analytics = TeacherAnalytics::for($teacher->id);

        $funnel = $analytics->funnel();
        $this->assertSame(2, $funnel['opened']);          // оба студента открыли
        $this->assertSame(1, $funnel['completed_any']);   // только s1 что-то прошёл
        $this->assertSame(4, $funnel['lessons_total']);
        $this->assertSame(2, $funnel['views_completed']);

        $progress = $analytics->studentProgress();
        // Сортировка по проценту: s2 (0%) сверху, s1 (50%) ниже.
        $this->assertSame($s2->id, $progress->first()['user_id']);
        $this->assertSame(0, $progress->first()['percent']);
        $s1row = $progress->firstWhere('user_id', $s1->id);
        $this->assertSame(50, $s1row['percent']);
        $this->assertSame(2, $s1row['completed']);
    }

    /** @test */
    public function daily_opens_buckets_first_opens_by_day(): void
    {
        $teacher = Teacher::create(['name' => 'T', 'email' => 't2@example.test']);
        $course = Course::factory()->create(['teacher_id' => $teacher->id]);
        $lessons = Lesson::factory()->count(3)->for($course)->create();
        $u = User::factory()->create();

        $this->recordView($u->id, $lessons[0], $course, false, now()->toDateTimeString());
        $this->recordView($u->id, $lessons[1], $course, false, now()->toDateTimeString());
        $this->recordView($u->id, $lessons[2], $course, false, now()->subDays(3)->toDateTimeString());

        $daily = TeacherAnalytics::for($teacher->id)->dailyOpens(30);

        $this->assertSame(2, $daily[now()->toDateString()]);
        $this->assertSame(1, $daily[now()->subDays(3)->toDateString()]);
        $this->assertCount(30, $daily);
    }

    /** @test */
    public function page_renders_for_teacher(): void
    {
        $teacher = Teacher::create(['name' => 'T', 'email' => 't3@example.test']);
        $teacherUser = User::factory()->create(['role' => Roles::TEACHER, 'teacher_id' => $teacher->id]);
        $course = Course::factory()->create(['teacher_id' => $teacher->id, 'title' => 'Грамматика']);
        $lesson = Lesson::factory()->for($course)->create();
        $student = User::factory()->create(['name' => 'Аруна']);
        $this->recordView($student->id, $lesson, $course, true);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs($teacherUser);

        $this->assertTrue(TeacherAnalyticsPage::canAccess());

        Livewire::test(TeacherAnalyticsPage::class)
            ->assertOk()
            ->assertSee('Аналитика студентов')
            ->assertSee('Аруна');
    }
}
