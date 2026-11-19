<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Донабор закрывается сам (MG 18-10-2026): enrolling-курс, у которого прошло
 * последнее занятие, переводится в «В записи» — иначе витрина вернёт бейдж
 * «Идет набор» и продолжит продавать блоки курса, на который уже не попасть.
 */
class CloseFinishedEnrollingCoursesTest extends TestCase
{
    use RefreshDatabase;

    /** Создаёт N прошедших занятий курса (настоящий поток, а не одиночные строки). */
    private function pastSessions(Course $course, int $n = 4): void
    {
        for ($i = $n; $i >= 1; $i--) {
            Schedule::create([
                'title' => 'Зан. '.$i,
                'start' => now()->subWeeks($i),
                'end' => now()->subWeeks($i)->addHours(2),
                'course_id' => $course->id,
            ]);
        }
    }

    /** @test */
    public function finished_enrolling_course_is_moved_to_recorded(): void
    {
        $course = Course::factory()->create(['title' => 'Finished Qqq', 'format' => 'enrolling']);
        $this->pastSessions($course);

        Artisan::call('courses:close-finished-enrolling');

        $this->assertSame('recorded', $course->fresh()->format);
    }

    /** @test */
    public function running_enrolling_course_stays_alone(): void
    {
        $course = Course::factory()->create(['title' => 'Running Qqq', 'format' => 'enrolling']);
        $this->pastSessions($course, 3);
        Schedule::create(['title' => 'Зан. 4', 'start' => now()->addWeek(), 'end' => now()->addWeek()->addHours(2), 'course_id' => $course->id]);

        Artisan::call('courses:close-finished-enrolling');

        $this->assertSame('enrolling', $course->fresh()->format);
    }

    /** @test */
    public function not_yet_started_enrolling_course_stays_alone(): void
    {
        $course = Course::factory()->create(['title' => 'Upcoming Qqq', 'format' => 'enrolling']);
        Schedule::create(['title' => 'Зан. 1', 'start' => now()->addWeek(), 'end' => now()->addWeek()->addHours(2), 'course_id' => $course->id]);

        Artisan::call('courses:close-finished-enrolling');

        $this->assertSame('enrolling', $course->fresh()->format);
    }

    /** @test */
    public function enrolling_course_without_calendar_is_not_touched(): void
    {
        $course = Course::factory()->create(['title' => 'No Calendar Qqq', 'format' => 'enrolling']);

        Artisan::call('courses:close-finished-enrolling');

        $this->assertSame('enrolling', $course->fresh()->format);
    }

    /** @test */
    public function short_calendar_is_not_a_finished_course(): void
    {
        // Две одиночные строки расписания в прошлом — не поток: такой курс
        // автозакрытие не трогает (иначе живой набор уедет в «В записи»).
        $course = Course::factory()->create(['title' => 'Two Pasts Qqq', 'format' => 'enrolling']);
        $this->pastSessions($course, 2);

        Artisan::call('courses:close-finished-enrolling');

        $this->assertSame('enrolling', $course->fresh()->format);
    }

    /** @test */
    public function dry_run_does_not_change_anything(): void
    {
        $course = Course::factory()->create(['title' => 'Finished Qqq', 'format' => 'enrolling']);
        $this->pastSessions($course);

        Artisan::call('courses:close-finished-enrolling', ['--dry-run' => true]);

        $this->assertSame('enrolling', $course->fresh()->format);
    }

    /** @test */
    public function course_option_limits_the_run_to_one_course(): void
    {
        $finished = Course::factory()->create(['title' => 'Finished Qqq', 'format' => 'enrolling']);
        $this->pastSessions($finished);

        $other = Course::factory()->create(['title' => 'Finished Rrr', 'format' => 'enrolling']);
        $this->pastSessions($other);

        Artisan::call('courses:close-finished-enrolling', ['--course' => $other->id]);

        $this->assertSame('enrolling', $finished->fresh()->format);
        $this->assertSame('recorded', $other->fresh()->format);
    }
}
