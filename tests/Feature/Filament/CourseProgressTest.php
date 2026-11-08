<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Pages\CourseProgress;
use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Services\Schedule\StreamProgressReport;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * «Ход курсов»: блок и занятие каждого потока — из расписания, не из
 * course_blocks. Номер — тег «(#N, …)» в титуле, блок — по 4 занятия.
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

    private function stream(string $courseTitle, string $groupName): array
    {
        $course = Course::factory()->create(['title' => $courseTitle, 'is_active' => true]);
        $group = Group::create(['name' => $groupName]);
        $course->groups()->attach($group);

        return [$course, $group];
    }

    private function lesson(Course $course, Group $group, string $at, string $title, bool $overview = false): Schedule
    {
        return Schedule::create([
            'title' => $title,
            'course_id' => $course->id,
            'group_id' => $group->id,
            'start' => Carbon::parse($at),
            'end' => Carbon::parse($at)->addHour(),
            'is_overview' => $overview,
        ]);
    }

    /** @return array<string, array<string, mixed>> */
    private function rowsByGroup(): array
    {
        return collect(app(StreamProgressReport::class)->rows())->keyBy('group_name')->all();
    }

    /** @test */
    public function number_and_block_come_from_the_title_tag(): void
    {
        [$course, $group] = $this->stream('Грамматика', 'гр.58');
        // #9–#10 прошли, #11 — 25.10 (блок 3, 3-е в блоке). #16 отменён мягко — но общий счёт по нему.
        $n = 9;
        foreach (['2026-10-11', '2026-10-18', '2026-10-25', '2026-11-01'] as $d) {
            $this->lesson($course, $group, $d.' 14:00', 'Грамматика (#'.$n.', '.Carbon::parse($d)->format('d.m.y').')');
            $n++;
        }
        $this->lesson($course, $group, '2026-11-29 14:00', 'Грамматика (#16, 29.11.26)')->delete();

        $row = $this->rowsByGroup()['гр.58'];

        $this->assertSame(11, $row['number']);
        $this->assertSame(3, $row['block']);
        $this->assertSame(3, $row['in_block']);
        $this->assertSame(16, $row['total']);
        $this->assertSame(StreamProgressReport::BY_TAG, $row['numbered_by']);
        $this->assertSame('2026-10-25', $row['next']->start->toDateString());
        $this->assertSame('2026-10-18', $row['last_past']->start->toDateString());
    }

    /** @test */
    public function without_tags_the_number_is_the_order_in_the_calendar_without_overview(): void
    {
        [$course, $group] = $this->stream('Йога-васиштха', 'чт 12:00');
        $this->lesson($course, $group, '2026-08-27 12:00', 'Обзорное', overview: true);
        foreach (['2026-09-03', '2026-09-10', '2026-09-17', '2026-09-24', '2026-10-01', '2026-10-08', '2026-10-15', '2026-10-22', '2026-10-29'] as $d) {
            $this->lesson($course, $group, $d.' 12:00', 'Йога-васиштха');
        }

        $row = $this->rowsByGroup()['чт 12:00'];

        $this->assertSame(9, $row['number']); // 29.10 — девятое, обзорное не в счёт
        $this->assertSame(3, $row['block']);
        $this->assertSame(1, $row['in_block']);
        $this->assertSame(9, $row['total']);
        $this->assertSame(StreamProgressReport::BY_ORDER, $row['numbered_by']);
    }

    /** @test */
    public function finished_stream_shows_its_last_lesson_and_old_streams_are_hidden(): void
    {
        [$course, $group] = $this->stream('Закончившийся', 'гр.done');
        $this->lesson($course, $group, '2026-10-20 18:00', 'Курс (#16, 20.10.26)');

        [$old, $oldGroup] = $this->stream('Давний', 'гр.old');
        $this->lesson($old, $oldGroup, '2026-06-01 18:00', 'Курс (#4, 01.06.26)');

        $rows = $this->rowsByGroup();

        $this->assertArrayNotHasKey('гр.old', $rows);
        $this->assertNull($rows['гр.done']['next']);
        $this->assertSame(4, $rows['гр.done']['block']);
        $this->assertSame(4, $rows['гр.done']['in_block']);
    }

    /** @test */
    public function page_renders_streams_for_admin(): void
    {
        [$course, $group] = $this->stream('Гита ход', 'вт 11:30');
        $this->lesson($course, $group, '2026-10-20 11:30', 'Гита (#6, 20.10.26)');
        $this->lesson($course, $group, '2026-10-27 11:30', 'Гита (#7, 27.10.26)');
        $admin = User::factory()->create(['role' => Roles::ADMIN, 'is_admin' => true]);

        Livewire::actingAs($admin)
            ->test(CourseProgress::class)
            ->assertSuccessful()
            ->assertSee('Гита ход')
            ->assertSee('Блок 2')
            ->assertSee('3-е из 4')
            ->assertSee('7-е из 7');
    }

    /** @test */
    public function teacher_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => Roles::TEACHER]));

        $this->assertFalse(CourseProgress::canAccess());
    }
}
