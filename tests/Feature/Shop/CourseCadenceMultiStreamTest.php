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
 * У курса может быть несколько потоков ОДНОЙ программы, и складывать их
 * календари нельзя.
 *
 * Живой случай (18-08-2026): «Напевный санскрит — гимн Бхагавадгиты (2 часть,
 * 2026)» идёт по вторникам 11:30 (группа 125) и по субботам 12:00 (группа 128).
 * Как только у субботнего потока появилось расписание, витрина написала
 * «осталось 10 занятий из 24» и «24 часа» — у курса из 16 часовых занятий.
 * Эти числа не описывали ни одного студента: каждый ходит ровно в один поток.
 */
class CourseCadenceMultiStreamTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-18 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Копия прод-формы курса 399: вторничный поток из 4 занятий (3 прошли),
     * субботний из 2 (оба впереди).
     */
    private function twoStreamCourse(): Course
    {
        $course = Course::factory()->create([
            'slug' => 'gita-two-streams',
            'format' => 'live',
            'is_visible' => true,
            'hours_count' => null,
        ]);
        Tariff::factory()->for($course)->create(['title' => 'Полный курс', 'type' => 'full', 'price' => 22000]);

        $tue = Group::create(['name' => 'вт 11:30']);
        $sat = Group::create(['name' => 'сб 12:00']);
        $course->groups()->attach([$tue->id, $sat->id]);

        foreach (['2026-07-28', '2026-08-04', '2026-08-11', '2026-08-25'] as $date) {
            Schedule::create([
                'title' => 'Вторник',
                'course_id' => $course->id,
                'group_id' => $tue->id,
                'start' => Carbon::parse($date.' 11:30'),
                'end' => Carbon::parse($date.' 12:30'),
            ]);
        }

        foreach (['2026-09-12', '2026-09-19'] as $date) {
            Schedule::create([
                'title' => 'Суббота',
                'course_id' => $course->id,
                'group_id' => $sat->id,
                'start' => Carbon::parse($date.' 12:00'),
                'end' => Carbon::parse($date.' 13:00'),
            ]);
        }

        return $course;
    }

    /** @test */
    public function totals_and_hours_take_the_longest_stream_not_the_sum(): void
    {
        $cadence = CourseCadence::for($this->twoStreamCourse());

        $this->assertTrue($cadence->hasMultipleStreams());
        $this->assertSame(2, $cadence->streams()->count());
        // 4 + 2 = 6 занятий на календаре, но программа — 4.
        $this->assertSame(4, $cadence->total());
        // 4 ч + 2 ч = 6 в сумме; длиннейший поток даёт 4.
        $this->assertSame(4, $cadence->hours());
    }

    /** @test */
    public function the_shared_progress_line_stays_silent_and_streams_speak_instead(): void
    {
        $cadence = CourseCadence::for($this->twoStreamCourse());

        $this->assertNull($cadence->progressLabel(), 'общий остаток описывал бы студента, которого нет');

        $lines = $cadence->streamLines();
        $this->assertCount(2, $lines);
        $this->assertContains('вт 11:30 — осталось 1 занятие из 4', $lines);
        // Субботний поток ещё не начался — прогресса нет, остаётся только слот.
        $this->assertContains('сб 12:00', $lines);
    }

    /** @test */
    public function the_slot_names_both_streams(): void
    {
        $this->assertSame('вт 11:30 · сб 12:00', CourseCadence::for($this->twoStreamCourse())->slotLabel());
    }

    /** @test */
    public function a_course_page_shows_per_stream_lines_and_no_summed_total(): void
    {
        $course = $this->twoStreamCourse();

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="course-stream-line"', $html);
        $this->assertStringContainsString('вт 11:30 — осталось 1 занятие из 4', $html);
        $this->assertStringContainsString('вт 11:30 · сб 12:00', $html);
        $this->assertStringNotContainsString('осталось 3 занятия из 6', $html);
        $this->assertStringContainsString('4 часа', $html);
        $this->assertStringNotContainsString('6 часов', $html);
    }

    /** @test */
    public function the_catalog_card_says_how_many_streams_instead_of_a_nobody_total(): void
    {
        $course = $this->twoStreamCourse();

        $html = $this->get(route('shop.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="course-card-streams"', $html);
        $this->assertStringContainsString('2 потока', $html);
        $this->assertStringNotContainsString('осталось 3 занятия из 6', $html);
    }

    /** @test */
    public function the_late_buyer_notice_names_the_streams(): void
    {
        $course = $this->twoStreamCourse();

        $html = $this->get('/k/'.$course->slug)->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="tariffs-underway-notice"', $html);
        $this->assertStringContainsString('Курс идёт в 2 потоках', $html);
        $this->assertStringContainsString('Вы занимаетесь в одном из них', $html);
    }

    /** @test */
    public function a_course_is_underway_when_any_single_stream_is(): void
    {
        $cadence = CourseCadence::for($this->twoStreamCourse());

        // Вторничный поток на 3 из 4, субботний ещё не начался.
        $this->assertTrue($cadence->isUnderway());
        $this->assertFalse($cadence->isFinished());
    }

    /** @test */
    public function a_course_is_finished_only_when_every_stream_is(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);
        $a = Group::create(['name' => 'A']);
        $b = Group::create(['name' => 'B']);
        $course->groups()->attach([$a->id, $b->id]);

        Schedule::create([
            'title' => 'A', 'course_id' => $course->id, 'group_id' => $a->id,
            'start' => Carbon::parse('2026-07-07 11:00'), 'end' => Carbon::parse('2026-07-07 12:00'),
        ]);
        Schedule::create([
            'title' => 'B', 'course_id' => $course->id, 'group_id' => $b->id,
            'start' => Carbon::parse('2026-09-05 11:00'), 'end' => Carbon::parse('2026-09-05 12:00'),
        ]);

        $cadence = CourseCadence::for($course);
        $this->assertFalse($cadence->isFinished(), 'один поток ещё впереди');

        Carbon::setTestNow('2026-10-01 00:00:00');
        $this->assertTrue(CourseCadence::for($course->fresh())->isFinished());
    }

    /** @test */
    public function a_single_stream_course_is_untouched_by_the_split(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);
        $group = Group::create(['name' => 'один поток']);
        $course->groups()->attach($group);

        foreach (['2026-08-04', '2026-08-11', '2026-08-25'] as $date) {
            Schedule::create([
                'title' => 'Занятие', 'course_id' => $course->id, 'group_id' => $group->id,
                'start' => Carbon::parse($date.' 11:30'), 'end' => Carbon::parse($date.' 12:30'),
            ]);
        }

        $cadence = CourseCadence::for($course);

        $this->assertFalse($cadence->hasMultipleStreams());
        $this->assertSame('по вторникам в 11:30', $cadence->slotLabel());
        $this->assertSame('осталось 1 занятие из 3', $cadence->progressLabel());
        $this->assertSame([], $cadence->streamLines());
        $this->assertSame(3, $cadence->hours());
    }

    /** @test */
    public function course_wide_sessions_without_a_group_are_one_stream(): void
    {
        $course = Course::factory()->create(['format' => 'live', 'is_visible' => true]);

        foreach (['2026-08-04', '2026-08-25'] as $date) {
            Schedule::create([
                'title' => 'Занятие', 'course_id' => $course->id, 'group_id' => null,
                'start' => Carbon::parse($date.' 19:00'), 'end' => Carbon::parse($date.' 20:00'),
            ]);
        }

        $cadence = CourseCadence::for($course);

        $this->assertFalse($cadence->hasMultipleStreams());
        $this->assertSame('осталось 1 занятие из 2', $cadence->progressLabel());
    }
}
