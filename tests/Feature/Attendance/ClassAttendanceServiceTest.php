<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Models\Course;
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

    /** @test */
    public function for_group_includes_course_tied_schedules_without_group_id(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Группа 4']);
        $group->courses()->attach($course->id);
        $student = User::factory()->create();
        $group->users()->attach($student->id);

        // Занятие привязано к курсу, group_id пуст (единая Zoom-ссылка курса).
        $s = Schedule::create([
            'title' => 'Созвон', 'start' => now()->subDay(),
            'group_id' => null, 'course_id' => $course->id,
        ]);
        WebinarAttendance::create([
            'schedule_id' => $s->id, 'user_id' => $student->id,
            'zoom_participant_uuid' => 'ct1', 'joined_at' => now(),
        ]);

        $matrix = app(ClassAttendanceService::class)->forGroup($group, now()->subWeek(), now());

        $this->assertCount(1, $matrix['schedules']);
        $this->assertSame('present', $matrix['status'][$student->id][$s->id]);
    }

    /** @test */
    public function dashboard_returns_empty_report_when_no_schedules(): void
    {
        $report = app(ClassAttendanceService::class)->dashboard(now()->subMonth(), now(), 3);

        $this->assertTrue($report['students']->isEmpty());
        $this->assertTrue($report['groups']->isEmpty());
        $this->assertTrue($report['courses']->isEmpty());
        $this->assertTrue($report['weekly']->isEmpty());
        $this->assertTrue($report['chronic']->isEmpty());
    }

    /** @test */
    public function dashboard_computes_rates_by_student_group_course_and_weekly_trend(): void
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => 'Группа 5']);
        $group->courses()->attach($course->id);
        $present = User::factory()->create(['name' => 'Present5']);
        $absent = User::factory()->create(['name' => 'Absent5']);
        $group->users()->attach([$present->id, $absent->id]);

        $s1 = Schedule::create(['title' => 'Зан. 1', 'start' => now()->subWeeks(2), 'group_id' => $group->id, 'course_id' => $course->id]);
        $s2 = Schedule::create(['title' => 'Зан. 2', 'start' => now()->subWeek(), 'group_id' => $group->id, 'course_id' => $course->id]);

        foreach ([$s1, $s2] as $s) {
            WebinarAttendance::create([
                'schedule_id' => $s->id, 'user_id' => $present->id,
                'zoom_participant_uuid' => 'w'.$s->id, 'joined_at' => $s->start, 'duration_seconds' => 1800,
            ]);
        }

        $report = app(ClassAttendanceService::class)->dashboard(now()->subMonth(), now(), 2);

        $byName = $report['students']->keyBy(fn ($r) => $r['user']->name);
        $this->assertSame(2, $byName['Present5']['expected']);
        $this->assertSame(2, $byName['Present5']['attended']);
        $this->assertSame(100, $byName['Present5']['rate']);
        $this->assertSame(0, $byName['Absent5']['rate']);

        $this->assertCount(1, $report['groups']);
        $this->assertSame($group->id, $report['groups']->first()['group']->id);

        $this->assertCount(1, $report['courses']);
        $this->assertSame($course->id, $report['courses']->first()['course']->id);

        $this->assertCount(2, $report['weekly']);

        // Absent5 missed both (threshold=2) consecutive classes -- chronic.
        $chronicNames = $report['chronic']->map(fn ($r) => $r['user']->name);
        $this->assertTrue($chronicNames->contains('Absent5'));
        $this->assertFalse($chronicNames->contains('Present5'));
    }

    /** @test */
    public function dashboard_chronic_absentees_require_threshold_consecutive_recent_misses(): void
    {
        $group = Group::create(['name' => 'Группа 6']);
        $student = User::factory()->create(['name' => 'Mixed6']);
        $group->users()->attach($student->id);

        $s1 = Schedule::create(['title' => 'Зан. 1', 'start' => now()->subWeeks(3), 'group_id' => $group->id]);
        $s2 = Schedule::create(['title' => 'Зан. 2', 'start' => now()->subWeeks(2), 'group_id' => $group->id]);
        Schedule::create(['title' => 'Зан. 3', 'start' => now()->subWeek(), 'group_id' => $group->id]);

        // Attended the OLDEST class only -- the two most recent are misses, but
        // that's below a threshold of 3 consecutive misses.
        WebinarAttendance::create([
            'schedule_id' => $s1->id, 'user_id' => $student->id,
            'zoom_participant_uuid' => 'x1', 'joined_at' => $s1->start,
        ]);

        $report = app(ClassAttendanceService::class)->dashboard(now()->subMonth(), now(), 3);
        $this->assertTrue($report['chronic']->isEmpty());

        $report2 = app(ClassAttendanceService::class)->dashboard(now()->subMonth(), now(), 2);
        $this->assertCount(1, $report2['chronic']);
    }
}
