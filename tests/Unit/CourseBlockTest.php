<?php

namespace Tests\Unit;

use App\Models\Course;
use App\Models\CourseBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CourseBlockTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function flag_is_current_makes_block_current_regardless_of_dates(): void
    {
        Carbon::setTestNow('2026-04-30 12:00:00');
        $course = Course::factory()->create();

        $block = CourseBlock::factory()
            ->for($course)
            ->withDates(Carbon::parse('2030-01-01'), Carbon::parse('2030-12-31'))
            ->current()
            ->create(['number' => 1]);

        $this->assertTrue($block->isCurrent());
    }

    /** @test */
    public function block_within_date_range_is_current(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = Course::factory()->create();

        $block = CourseBlock::factory()
            ->for($course)
            ->withDates(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'))
            ->create(['number' => 1]);

        $this->assertTrue($block->isCurrent());
    }

    /** @test */
    public function block_before_start_is_not_current(): void
    {
        Carbon::setTestNow('2026-03-15 12:00:00');
        $course = Course::factory()->create();

        $block = CourseBlock::factory()
            ->for($course)
            ->withDates(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'))
            ->create(['number' => 1]);

        $this->assertFalse($block->isCurrent());
    }

    /** @test */
    public function block_after_end_is_not_current(): void
    {
        Carbon::setTestNow('2026-05-15 12:00:00');
        $course = Course::factory()->create();

        $block = CourseBlock::factory()
            ->for($course)
            ->withDates(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'))
            ->create(['number' => 1]);

        $this->assertFalse($block->isCurrent());
    }

    /** @test */
    public function end_date_is_inclusive_for_the_full_day(): void
    {
        // Регрессия: ends_at=2026-04-30 00:00:00 + сейчас 2026-04-30 13:50 → блок ВСЁ ЕЩЁ актуален.
        Carbon::setTestNow('2026-04-30 13:50:00');
        $course = Course::factory()->create();

        $block = CourseBlock::factory()
            ->for($course)
            ->withDates(Carbon::parse('2026-04-01 00:00:00'), Carbon::parse('2026-04-30 00:00:00'))
            ->create(['number' => 1]);

        $this->assertTrue($block->isCurrent(), 'Блок с ends_at=сегодня должен оставаться актуальным до конца дня');
    }

    /** @test */
    public function block_without_dates_and_without_flag_is_not_current(): void
    {
        $course = Course::factory()->create();

        $block = CourseBlock::factory()
            ->for($course)
            ->create(['number' => 1]);

        $this->assertFalse($block->isCurrent());
    }

    /** @test */
    public function scope_current_returns_only_current_blocks(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = Course::factory()->create();

        $past = CourseBlock::factory()->for($course)
            ->withDates(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'))
            ->create(['number' => 1]);

        $now = CourseBlock::factory()->for($course)
            ->withDates(Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'))
            ->create(['number' => 2]);

        $future = CourseBlock::factory()->for($course)
            ->withDates(Carbon::parse('2027-01-01'), Carbon::parse('2027-01-31'))
            ->create(['number' => 3]);

        $forced = CourseBlock::factory()->for($course)->current()
            ->create(['number' => 4]);

        $ids = CourseBlock::current()->pluck('id')->all();

        $this->assertContains($now->id, $ids);
        $this->assertContains($forced->id, $ids);
        $this->assertNotContains($past->id, $ids);
        $this->assertNotContains($future->id, $ids);
    }

    /** @test */
    public function course_current_block_returns_lowest_numbered_current(): void
    {
        Carbon::setTestNow('2026-04-15 12:00:00');
        $course = Course::factory()->create();

        CourseBlock::factory()->for($course)->current()->create(['number' => 5]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 2]);
        CourseBlock::factory()->for($course)->current()->create(['number' => 7]);

        $this->assertSame(2, $course->currentBlock()?->number);
    }

    /** @test */
    public function course_current_block_returns_null_when_none_match(): void
    {
        $course = Course::factory()->create();
        CourseBlock::factory()->for($course)->create(['number' => 1]);

        $this->assertNull($course->currentBlock());
    }
}
