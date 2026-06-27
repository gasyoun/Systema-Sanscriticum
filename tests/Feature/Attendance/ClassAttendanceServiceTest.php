<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use App\Models\WebinarAttendance;
use App\Services\ClassAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClassAttendanceServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function for_schedule_classifies_present_clicked_absent_and_guests(): void
    {
        $group = Group::create(['name' => 'Группа 1']);
        $present = User::factory()->create(['name' => 'Present', 'email' => 'p@example.test']);
        $clicked = User::factory()->create(['name' => 'Clicked', 'email' => 'c@example.test']);
        $absent = User::factory()->create(['name' => 'Absent', 'email' => 'a@example.test']);
        $group->users()->attach([$present->id, $clicked->id, $absent->id]);

        $schedule = Schedule::create([
            'title' => 'Занятие', 'start' => now()->subHour(), 'group_id' => $group->id,
        ]);

        // Present — Zoom опознал (есть запись с user_id и длительностью).
        WebinarAttendance::create([
            'schedule_id' => $schedule->id, 'user_id' => $present->id,
            'zoom_participant_uuid' => 'p1', 'name' => 'Present',
            'joined_at' => now()->subHour(), 'duration_seconds' => 5400,
        ]);
        // Clicked — перешёл по трекинг-ссылке, Zoom не опознал.
        ScheduleJoinClick::record($schedule->id, $clicked->id, 'cabinet');
        // Гость — запись без user_id.
        WebinarAttendance::create([
            'schedule_id' => $schedule->id, 'user_id' => null,
            'zoom_participant_uuid' => 'g1', 'name' => 'Неизвестный гость',
        ]);

        $result = app(ClassAttendanceService::class)->forSchedule($schedule);

        $this->assertSame(['expected' => 3, 'present' => 1, 'clicked' => 1, 'absent' => 1, 'guests' => 1], $result['summary']);

        $byName = $result['roster']->keyBy(fn ($r) => $r['user']->name);
        $this->assertSame('present', $byName['Present']['status']);
        $this->assertSame(90, $byName['Present']['minutes']);
        $this->assertSame('clicked', $byName['Clicked']['status']);
        $this->assertSame('cabinet', $byName['Clicked']['click_source']);
        $this->assertSame('absent', $byName['Absent']['status']);
        $this->assertCount(1, $result['guests']);
    }

    /** @test */
    public function for_student_returns_recent_sessions_with_status(): void
    {
        $group = Group::create(['name' => 'Группа 2']);
        $student = User::factory()->create();
        $group->users()->attach($student->id);

        $past = Schedule::create(['title' => 'Прошлое', 'start' => now()->subDay(), 'group_id' => $group->id]);
        Schedule::create(['title' => 'Будущее', 'start' => now()->addDay(), 'group_id' => $group->id]);

        WebinarAttendance::create([
            'schedule_id' => $past->id, 'user_id' => $student->id,
            'zoom_participant_uuid' => 's1', 'joined_at' => now()->subDay(), 'duration_seconds' => 3600,
        ]);

        $history = app(ClassAttendanceService::class)->forStudent($student);

        // Только прошедшее занятие, со статусом present.
        $this->assertCount(1, $history);
        $this->assertSame('present', $history->first()['status']);
        $this->assertSame($past->id, $history->first()['schedule']->id);
    }

    /** @test */
    public function for_group_builds_matrix(): void
    {
        $group = Group::create(['name' => 'Группа 3']);
        $s1 = User::factory()->create();
        $group->users()->attach($s1->id);

        $a = Schedule::create(['title' => 'Зан. 1', 'start' => now()->subDays(2), 'group_id' => $group->id]);
        $b = Schedule::create(['title' => 'Зан. 2', 'start' => now()->subDay(), 'group_id' => $group->id]);

        WebinarAttendance::create([
            'schedule_id' => $a->id, 'user_id' => $s1->id, 'zoom_participant_uuid' => 'm1', 'joined_at' => now(),
        ]);
        ScheduleJoinClick::record($b->id, $s1->id, 'telegram');

        $matrix = app(ClassAttendanceService::class)->forGroup($group, now()->subWeek(), now());

        $this->assertCount(2, $matrix['schedules']);
        $this->assertSame('present', $matrix['status'][$s1->id][$a->id]);
        $this->assertSame('clicked', $matrix['status'][$s1->id][$b->id]);
    }
}
