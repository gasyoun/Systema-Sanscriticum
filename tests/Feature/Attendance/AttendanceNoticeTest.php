<?php

declare(strict_types=1);

namespace Tests\Feature\Attendance;

use App\Jobs\SendMessengerAlerts;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Models\User;
use App\Services\AttendanceNoticeService;
use App\Services\Bot\StudentSelfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class AttendanceNoticeTest extends TestCase
{
    use RefreshDatabase;

    private function enableNotices(): void
    {
        config()->set('features.attendance_notices', true);
    }

    private function studentWithUpcoming(): array
    {
        $group = Group::create(['name' => 'Группа А', 'telegram_chat_id' => '-100123']);
        $student = User::factory()->create(['telegram_id' => 4242]);
        $group->users()->attach($student->id);

        $schedule = Schedule::create([
            'title' => 'Санскрит live',
            'group_id' => $group->id,
            'start' => now()->addDay()->setTime(18, 0),
            'end' => now()->addDay()->setTime(20, 0),
        ]);

        return compact('group', 'student', 'schedule');
    }

    /** @test */
    public function cabinet_store_saves_notice_when_flag_on(): void
    {
        $this->enableNotices();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithUpcoming();

        $this->actingAs($student)
            ->post(route('student.calendar.notice.store', $schedule), [
                'status' => ScheduleAttendanceNotice::STATUS_LATE,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('schedule_attendance_notices', [
            'user_id' => $student->id,
            'schedule_id' => $schedule->id,
            'status' => ScheduleAttendanceNotice::STATUS_LATE,
            'source' => ScheduleAttendanceNotice::SOURCE_CABINET,
        ]);
    }

    /** @test */
    public function cabinet_store_404_when_flag_off(): void
    {
        config()->set('features.attendance_notices', false);
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithUpcoming();

        $this->actingAs($student)
            ->post(route('student.calendar.notice.store', $schedule), [
                'status' => ScheduleAttendanceNotice::STATUS_ABSENT,
            ])
            ->assertNotFound();
    }

    /** @test */
    public function cabinet_destroy_clears_notice(): void
    {
        $this->enableNotices();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithUpcoming();

        ScheduleAttendanceNotice::create([
            'user_id' => $student->id,
            'schedule_id' => $schedule->id,
            'status' => ScheduleAttendanceNotice::STATUS_ABSENT,
            'source' => ScheduleAttendanceNotice::SOURCE_CABINET,
        ]);

        $this->actingAs($student)
            ->delete(route('student.calendar.notice.destroy', $schedule))
            ->assertRedirect();

        $this->assertDatabaseMissing('schedule_attendance_notices', [
            'user_id' => $student->id,
            'schedule_id' => $schedule->id,
        ]);
    }

    /** @test */
    public function stranger_cannot_notice_foreign_schedule(): void
    {
        $this->enableNotices();
        ['schedule' => $schedule] = $this->studentWithUpcoming();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post(route('student.calendar.notice.store', $schedule), [
                'status' => ScheduleAttendanceNotice::STATUS_ABSENT,
            ])
            ->assertForbidden();
    }

    /** @test */
    public function bot_phrases_map_to_statuses(): void
    {
        $svc = app(AttendanceNoticeService::class);

        $this->assertSame(ScheduleAttendanceNotice::STATUS_ABSENT, $svc->matchIntent('не смогу на урок'));
        $this->assertSame(ScheduleAttendanceNotice::STATUS_ABSENT, $svc->matchIntent('/absent'));
        $this->assertSame(ScheduleAttendanceNotice::STATUS_LATE, $svc->matchIntent('опоздаю на начало'));
        $this->assertSame(ScheduleAttendanceNotice::STATUS_LEAVE_EARLY, $svc->matchIntent('уйду раньше'));
        $this->assertSame(ScheduleAttendanceNotice::STATUS_UNCERTAIN, $svc->matchIntent('возможно не буду'));
        $this->assertSame(ScheduleAttendanceNotice::STATUS_UNCERTAIN, $svc->matchIntent('проблемная связь'));
        $this->assertSame('clear', $svc->matchIntent('буду на уроке'));
        $this->assertNull($svc->matchIntent('сколько стоит курс?'));
    }

    /** @test */
    public function bot_handler_writes_notice_for_upcoming(): void
    {
        $this->enableNotices();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithUpcoming();

        $reply = app(StudentSelfService::class)->handleAttendanceNotice(
            $student,
            'не смогу на занятие',
            ScheduleAttendanceNotice::SOURCE_TELEGRAM,
        );

        $this->assertTrue($reply['ok']);
        $this->assertStringContainsString('Не сможет быть', $reply['text']);
        $this->assertDatabaseHas('schedule_attendance_notices', [
            'user_id' => $student->id,
            'schedule_id' => $schedule->id,
            'status' => ScheduleAttendanceNotice::STATUS_ABSENT,
        ]);
    }

    /** @test */
    public function notify_absent_skips_pre_notified_absent(): void
    {
        Bus::fake();
        MarketingSetting::create([
            'absent_notify_enabled' => true,
            'absent_notify_delay_minutes' => 30,
        ]);
        MarketingSetting::flushCached();

        $group = Group::create(['name' => 'G']);
        $preWarned = User::factory()->create(['telegram_id' => 111, 'email' => 'w@example.test']);
        $silent = User::factory()->create(['telegram_id' => 222, 'email' => 's@example.test']);
        $group->users()->attach([$preWarned->id, $silent->id]);

        $schedule = Schedule::create([
            'title' => 'Прошлое',
            'group_id' => $group->id,
            'start' => now()->subHours(3)->subMinutes(30),
            'end' => now()->subHours(1)->subMinutes(30),
        ]);

        ScheduleAttendanceNotice::create([
            'user_id' => $preWarned->id,
            'schedule_id' => $schedule->id,
            'status' => ScheduleAttendanceNotice::STATUS_ABSENT,
            'source' => ScheduleAttendanceNotice::SOURCE_TELEGRAM,
        ]);

        $this->artisan('classes:notify-absent')->assertSuccessful();

        Bus::assertDispatchedTimes(SendMessengerAlerts::class, 1);
        Bus::assertDispatched(SendMessengerAlerts::class, fn ($job) => $job->user->id === $silent->id);
    }
}
