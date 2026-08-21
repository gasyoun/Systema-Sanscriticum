<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Support\CourseListHeading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CourseListHeadingTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function counts_courses_and_lesson_date_range(): void
    {
        $a = Course::factory()->create();
        $b = Course::factory()->create();
        Lesson::factory()->for($a)->create(['lesson_date' => '2024-01-10']);
        Lesson::factory()->for($b)->create(['lesson_date' => '2026-08-21']);

        $heading = CourseListHeading::forQuery(Course::query());

        $this->assertStringContainsString('2 онлайн-курса', $heading);
        $this->assertStringContainsString('с 10.01.2024 по 21.08.2026', $heading);
        $this->assertStringContainsString('занятия не идут', $heading);
    }

    /** @test */
    public function names_courses_that_still_have_classes(): void
    {
        $course = Course::factory()->create();
        Lesson::factory()->for($course)->create(['lesson_date' => now()->toDateString()]);
        Schedule::create([
            'title' => 'Прошло',
            'course_id' => $course->id,
            'start' => now()->subWeek(),
            'end' => now()->subWeek()->addHour(),
        ]);
        Schedule::create([
            'title' => 'Будет',
            'course_id' => $course->id,
            'start' => now()->addWeek(),
            'end' => now()->addWeek()->addHour(),
        ]);

        $heading = CourseListHeading::forQuery(Course::query()->whereKey($course->id));

        $this->assertStringContainsString('1 онлайн-курс', $heading);
        $this->assertStringContainsString('продолжаются занятия в 1 курсе', $heading);
    }
}
