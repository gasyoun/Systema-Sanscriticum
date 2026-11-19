<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Support\CourseHeroSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H6570: ручное расписание ещё не стартовавшего курса в хиро /k/<slug> —
 * свёрнутый блок со списком дат (контент-JSON resources/data/course-hero-schedules.json,
 * ключ = slug). Хардкод дат в ассертах — сознательный: это публичные
 * обещания на странице продаж; правка JSON без пересчёта должен ломать тест.
 */
class CourseHeroScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_hero_schedule_renders_computed_dates_for_listed_slug(): void
    {
        Course::factory()->create(['slug' => 'filosofiya-sankhi']);

        $this->get(route('shop.course.show', 'filosofiya-sankhi'))
            ->assertOk()
            ->assertSee('data-testid="course-hero-schedule"', false)
            ->assertSee('по понедельникам, 18:00–20:00 (МСК)', false)
            ->assertSee('1-е занятие: 19 октября 2026 (понедельник), 18:00', false)
            ->assertSee('16-е занятие: 1 февраля 2027 (понедельник), 18:00', false)
            ->assertSee('Все 16 дат', false);
    }

    public function test_hero_schedule_absent_for_unlisted_slug(): void
    {
        $course = Course::factory()->create();

        $this->get(route('shop.course.show', $course->slug))
            ->assertOk()
            ->assertDontSee('data-testid="course-hero-schedule"', false);
    }

    public function test_ru_date_formatting_is_locale_independent(): void
    {
        $this->assertSame(
            '1 февраля 2027 (понедельник)',
            CourseHeroSchedule::ruDate(Carbon::parse('2027-02-01')),
        );
        $this->assertSame(
            '31 декабря 2026 (четверг)',
            CourseHeroSchedule::ruDate(Carbon::parse('2026-12-31')),
        );
    }
}
