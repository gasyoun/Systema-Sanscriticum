<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\Tariff;
use App\Support\CourseCadence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Витрина обязана отвечать «когда именно идёт» и «сколько осталось» — из
 * календаря, а не из ручного бейджа `courses.format`.
 *
 * Живой случай (18-08-2026): «Напевный санскрит — гимн Бхагавадгиты (2 часть,
 * 2026)» стоял в каталоге с бейджем «Идет сейчас», без дня, без времени и без
 * того, что пройдено 14 занятий из 16. Два соседних потока Патанджали при этом
 * не показывали даже часов — `hours_count` у них просто не заполнен.
 */
class CourseCadenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Вторник, между занятием #14 (11:30–12:30) и занятием #15 (25.08, 11:00).
        Carbon::setTestNow('2026-08-18 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Копия прод-расписания курса 399: 16 вторников, штатное время 11:00,
     * три разовых сдвига на 11:30.
     */
    private function gitaCourse(): Course
    {
        $course = Course::factory()->create([
            'slug' => 'napevnyi-bxagavadgita-2ch-2026',
            'title' => 'Напевный санскрит - гимн Бхагавадгиты (2 часть, 2026)',
            'format' => 'live',
            'hours_count' => null,
            'lessons_count' => 16,
            'is_visible' => true,
        ]);
        Tariff::factory()->for($course)->create(['title' => 'Полный курс', 'price' => 22000]);

        $shifted = ['2026-07-28', '2026-08-11', '2026-08-18'];
        $day = Carbon::parse('2026-05-19');

        for ($i = 1; $i <= 16; $i++) {
            $time = in_array($day->toDateString(), $shifted, true) ? '11:30' : '11:00';
            Schedule::create([
                'title' => "Гимн Бхагавадгиты (#{$i})",
                'course_id' => $course->id,
                'start' => Carbon::parse($day->toDateString().' '.$time),
                'end' => Carbon::parse($day->toDateString().' 12:30'),
            ]);
            $day = $day->copy()->addWeek();
        }

        return $course;
    }

    /** @test */
    public function slot_label_uses_the_regular_time_not_the_one_off_shifts(): void
    {
        $cadence = CourseCadence::for($this->gitaCourse());

        // 13 занятий в 11:00 против 3 сдвинутых на 11:30 — штатный слот 11:00.
        $this->assertSame('по вторникам в 11:00', $cadence->slotLabel());
    }

    /** @test */
    public function progress_counts_what_is_left_not_what_was_promised(): void
    {
        $cadence = CourseCadence::for($this->gitaCourse());

        $this->assertSame(16, $cadence->total());
        $this->assertSame(14, $cadence->past());
        $this->assertSame(2, $cadence->remaining());
        $this->assertTrue($cadence->isUnderway());
        $this->assertFalse($cadence->isFinished());
        $this->assertSame('осталось 2 занятия из 16', $cadence->progressLabel());
        $this->assertSame('25 августа, вт, 11:00', $cadence->nextLabel());
    }

    /** @test */
    public function hours_are_derived_from_the_calendar_when_the_manual_field_is_empty(): void
    {
        // 13 занятий по 1,5 ч + 3 по 1 ч = 22,5 → 23 астрономических часа.
        $this->assertSame(23, CourseCadence::for($this->gitaCourse())->hours());
    }

    /** @test */
    public function course_page_shows_the_day_time_and_remaining_sessions(): void
    {
        $course = $this->gitaCourse();

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('по вторникам в 11:00', $html);
        $this->assertStringContainsString('25 августа, вт, 11:00', $html);
        $this->assertStringContainsString('осталось 2 занятия из 16', $html);
        $this->assertStringContainsString('День и время', $html);
        // Часов в базе нет — витрина всё равно называет длительность.
        $this->assertStringContainsString('23 часа', $html);
    }

    /** @test */
    public function catalog_card_carries_the_same_cadence_line(): void
    {
        $course = $this->gitaCourse();

        $html = $this->get(route('shop.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="course-card-cadence"', $html);
        $this->assertStringContainsString('по вторникам в 11:00', $html);
        $this->assertStringContainsString('осталось 2 занятия из 16', $html);
        $this->assertStringContainsString($course->slug, $html);
    }

    /** @test */
    public function schedule_attached_through_the_group_counts_too(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);
        $group = Group::create(['name' => 'Поток вс 10:00']);
        $course->groups()->attach($group);

        foreach (['2026-08-16', '2026-08-23', '2026-08-30'] as $date) {
            Schedule::create([
                'title' => 'Рецитация сутр Патанджали',
                'group_id' => $group->id,
                'start' => Carbon::parse($date.' 10:00'),
                'end' => Carbon::parse($date.' 11:30'),
            ]);
        }

        $cadence = CourseCadence::for($course);

        $this->assertSame('по воскресеньям в 10:00', $cadence->slotLabel());
        $this->assertSame(1, $cadence->past());
        $this->assertSame('осталось 2 занятия из 3', $cadence->progressLabel());
    }

    /** @test */
    public function two_weekdays_are_listed_side_by_side(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);

        foreach (['2026-09-01 15:00', '2026-09-03 19:00', '2026-09-08 15:00', '2026-09-10 19:00'] as $at) {
            Schedule::create([
                'title' => 'Занятие',
                'course_id' => $course->id,
                'start' => Carbon::parse($at),
                'end' => Carbon::parse($at)->addMinutes(90),
            ]);
        }

        $this->assertSame('вт 15:00 · чт 19:00', CourseCadence::for($course)->slotLabel());
    }

    /** @test */
    public function a_course_that_has_not_started_shows_no_progress_line(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);

        foreach (['2026-09-01 15:00', '2026-09-08 15:00'] as $at) {
            Schedule::create([
                'title' => 'Занятие',
                'course_id' => $course->id,
                'start' => Carbon::parse($at),
                'end' => Carbon::parse($at)->addMinutes(90),
            ]);
        }

        $cadence = CourseCadence::for($course);

        $this->assertNull($cadence->progressLabel(), 'до старта «осталось 2 из 2» ничего не сообщает');
        $this->assertSame('по вторникам в 15:00', $cadence->slotLabel());
    }

    /** @test */
    public function a_finished_course_says_so_instead_of_inventing_a_next_date(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);

        foreach (['2026-07-07 15:00', '2026-07-14 15:00'] as $at) {
            Schedule::create([
                'title' => 'Занятие',
                'course_id' => $course->id,
                'start' => Carbon::parse($at),
                'end' => Carbon::parse($at)->addMinutes(90),
            ]);
        }

        $cadence = CourseCadence::for($course);

        $this->assertTrue($cadence->isFinished());
        $this->assertNull($cadence->next());
        $this->assertNull($cadence->nextLabel());
        $this->assertSame('все 2 занятия прошли', $cadence->progressLabel());
    }

    /** @test */
    public function a_course_without_a_calendar_stays_silent(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);
        $cadence = CourseCadence::for($course);

        $this->assertFalse($cadence->hasCalendar());
        $this->assertNull($cadence->slotLabel());
        $this->assertNull($cadence->nextLabel());
        $this->assertNull($cadence->progressLabel());
        $this->assertNull($cadence->hours());

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="course-hero-cadence"', $html);
    }

    /** @test */
    public function a_manual_hours_value_still_wins_over_the_derived_one(): void
    {
        $course = $this->gitaCourse();
        $course->update(['hours_count' => 20]);

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('20 часов', $html);
        $this->assertStringNotContainsString('23 часа', $html);
    }

    /** @test */
    public function json_ld_workload_appears_even_without_a_manual_hours_value(): void
    {
        $course = $this->gitaCourse();

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        // Раньше без hours_count CourseInstance не выводился вовсе — курс
        // выпадал из «Course Info» Google.
        $this->assertStringContainsString('CourseInstance', $html);
        $this->assertStringContainsString('PT23H', $html);
    }

    /** @test */
    public function the_catalog_stays_at_a_constant_query_count_for_many_live_courses(): void
    {
        foreach (range(1, 6) as $i) {
            $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);
            Schedule::create([
                'title' => "Занятие {$i}",
                'course_id' => $course->id,
                'start' => Carbon::parse('2026-09-01 15:00'),
                'end' => Carbon::parse('2026-09-01 16:30'),
            ]);
        }

        $queries = 0;
        \DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->get(route('shop.index'))->assertOk();

        // Каденция стоит ровно два запроса на весь каталог (course_group +
        // schedules), а не по два на карточку.
        $this->assertLessThan(40, $queries, "каталог сделал {$queries} запросов — похоже на N+1");
    }
}
