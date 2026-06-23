<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Schedule;
use App\Models\Teacher;
use App\Services\MonthlyScheduleDigest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonthlyScheduleDigestTest extends TestCase
{
    use RefreshDatabase;

    private function digest(): MonthlyScheduleDigest
    {
        return app(MonthlyScheduleDigest::class);
    }

    /** @test */
    public function it_lists_course_running_this_month_with_teacher_schedule_and_link(): void
    {
        $teacher = Teacher::create(['name' => 'Кочергина', 'email' => 'k@example.test']);
        $course = Course::factory()->create([
            'is_active' => true, 'is_visible' => true, 'slug' => 'kurs-a', 'title' => 'Курс А',
            'teacher_id' => $teacher->id,
        ]);
        CourseBlock::factory()->for($course)
            ->withDates(now()->startOfMonth(), now()->endOfMonth())
            ->create(['number' => 1]);
        Schedule::create([
            'title' => 'Занятие',
            'course_id' => $course->id,
            'start' => now()->startOfMonth()->addDays(2)->setTime(14, 0),
            'end' => now()->startOfMonth()->addDays(2)->setTime(15, 30),
        ]);

        $courses = $this->digest()->courses();

        $this->assertCount(1, $courses);
        $this->assertSame('Курс А', $courses[0]['title']);
        $this->assertSame('Кочергина', $courses[0]['teacher']);
        $this->assertStringContainsString('14:00', (string) $courses[0]['schedule']);
        $this->assertStringContainsString('kurs-a', (string) $courses[0]['url']);

        $this->assertStringContainsString('Курс А', $this->digest()->textTelegram());
        $this->assertStringContainsString('kurs-a', $this->digest()->textVk());
        $this->assertStringContainsString('ревнителей санскрита', $this->digest()->textTelegram());
    }

    /** @test */
    public function it_excludes_courses_not_running_this_month_and_hidden_ones(): void
    {
        // Блок в прошлом месяце, не is_current → не идёт сейчас.
        $past = Course::factory()->create(['is_active' => true, 'is_visible' => true, 'slug' => 'past', 'title' => 'Прошлый']);
        CourseBlock::factory()->for($past)
            ->withDates(now()->subMonthNoOverflow()->startOfMonth(), now()->subMonthNoOverflow()->endOfMonth())
            ->create(['number' => 1]);

        // Идёт в этом месяце, но скрыт с витрины → не постим.
        $hidden = Course::factory()->create(['is_active' => true, 'is_visible' => false, 'slug' => 'hidden', 'title' => 'Скрытый']);
        CourseBlock::factory()->for($hidden)
            ->withDates(now()->startOfMonth(), now()->endOfMonth())
            ->create(['number' => 1]);

        $this->assertSame([], $this->digest()->courses());
    }
}
