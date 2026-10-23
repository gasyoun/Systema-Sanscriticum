<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\CourseProgress;
use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Support\CourseCadence;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Ход курсов»: на каком занятии ТЕКУЩЕГО блока сейчас каждый поток курса.
 * Счёт по календарю внутри дат блока, обзорные не считаются, потоки не
 * складываются.
 */
class CourseProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-23 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function lesson(Course $course, ?Group $group, string $at, bool $overview = false): void
    {
        Schedule::create([
            'title' => 'Занятие',
            'course_id' => $course->id,
            'group_id' => $group?->id,
            'start' => Carbon::parse($at),
            'end' => Carbon::parse($at)->addHour(),
            'is_overview' => $overview,
        ]);
    }

    /**
     * Блок 2 идёт с 01.10 по 30.11. Вторничный поток: обзорное + 3 прошедших
     * + 2 будущих в блоке, плюс занятие блока 1 (сентябрь) — не в счёт.
     * Субботний поток: 1 прошедшее + 3 будущих.
     */
    private function twoStreamCourse(): Course
    {
        $course = Course::factory()->create(['title' => 'Гита ход', 'is_active' => true]);
        CourseBlock::factory()->for($course)->withDates(Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))->create(['number' => 1]);
        CourseBlock::factory()->for($course)->withDates(Carbon::parse('2026-10-01'), Carbon::parse('2026-11-30'))->create(['number' => 2]);

        $tue = Group::create(['name' => 'вт 11:30']);
        $sat = Group::create(['name' => 'сб 12:00']);
        $course->groups()->attach([$tue->id, $sat->id]);

        $this->lesson($course, $tue, '2026-09-29 11:30');
        $this->lesson($course, $tue, '2026-10-01 11:30', overview: true);
        foreach (['2026-10-06', '2026-10-13', '2026-10-20', '2026-10-27', '2026-11-03'] as $d) {
            $this->lesson($course, $tue, $d.' 11:30');
        }
        foreach (['2026-10-17', '2026-10-24', '2026-10-31', '2026-11-07'] as $d) {
            $this->lesson($course, $sat, $d.' 12:00');
        }

        return $course;
    }

    /** @test */
    public function block_streams_count_only_lessons_inside_block_dates_per_stream(): void
    {
        $course = $this->twoStreamCourse();
        $block = $course->currentBlock();

        $streams = CourseCadence::for($course)->blockStreams($block->starts_at, $block->ends_at);

        $this->assertSame(2, $block->number);
        $this->assertCount(2, $streams);

        $byPast = collect($streams)->keyBy('past');

        $this->assertSame(5, $byPast[3]['total']); // вторник: без обзорного и без сентябрьского
        $this->assertSame('2026-10-27', $byPast[3]['next']->start->toDateString());
        $this->assertSame('2026-10-20', $byPast[3]['last_past']->start->toDateString());

        $this->assertSame(4, $byPast[1]['total']); // суббота
        $this->assertSame('2026-10-24', $byPast[1]['next']->start->toDateString());
    }

    /** @test */
    public function page_shows_current_block_lesson_per_stream_for_admin(): void
    {
        $this->twoStreamCourse();
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CourseProgress::class)
            ->assertSuccessful()
            ->assertSee('Гита ход')
            ->assertSee('Блок 2')
            ->assertSee('4-е из 5')
            ->assertSee('2-е из 4')
            ->assertDontSee('из 9'); // потоки не сложены
    }

    /** @test */
    public function course_between_blocks_is_listed_with_next_block(): void
    {
        $course = Course::factory()->create(['title' => 'Курс на паузе', 'is_active' => true]);
        CourseBlock::factory()->for($course)->withDates(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-10'))->create(['number' => 1]);
        CourseBlock::factory()->for($course)->withDates(Carbon::parse('2026-11-10'), Carbon::parse('2026-12-20'))->create(['number' => 2]);
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CourseProgress::class)
            ->assertSuccessful()
            ->assertSee('Между блоками')
            ->assertSee('Курс на паузе')
            ->assertSee('10.11.2026');
    }

    /** @test */
    public function teacher_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));

        $this->assertFalse(CourseProgress::canAccess());
    }
}
