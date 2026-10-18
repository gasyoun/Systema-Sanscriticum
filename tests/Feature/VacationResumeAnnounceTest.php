<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendMessengerAlerts;
use App\Jobs\SendTelegramChatMessageJob;
use App\Jobs\SendZapisiBotMessageJob;
use App\Mail\VacationResumeMail;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\User;
use App\Models\VacationResumeNotice;
use App\Services\ClassAttendanceService;
use App\Services\Telegram\SlotNoticeService;
use App\Services\VacationResumeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * H6343: каскад анонса возобновления после каникул (T−7д/T−1д 20:00 МСК),
 * гейт паузы ≥21 день, явка-алерт <50%, пауза биллинг-арифметики на
 * каникульных строках, soft-delete фантомных строк группы 89 (dry-run 18 строк).
 */
class VacationResumeAnnounceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_no_announce_when_pause_below_21_days(): void
    {
        Carbon::setTestNow('2026-10-10 20:05:00');
        $group = $this->seedGroup(pauseDays: 20, resumeDay: '2026-10-17');

        $due = app(VacationResumeService::class)->duePhase($group, today());

        $this->assertNull($due, 'Пауза 20 дней — гейта нет, анонс не уходит.');
    }

    public function test_t7_wave_announces_after_21_day_pause(): void
    {
        // Суббота 09:00, как группа 89: пауза #20 (20.06) → возобновление 17.10 (119 дней).
        Carbon::setTestNow('2026-10-10 20:05:00');
        $group = $this->seedGroup(pauseDays: 120, resumeDay: '2026-10-17');

        $due = app(VacationResumeService::class)->duePhase($group, today());
        $this->assertNotNull($due, 'Пауза ≥21 день, до возобновления ровно 7 дней — волна t7.');
        $this->assertSame(VacationResumeNotice::PHASE_T7, $due['phase']);

        $result = app(VacationResumeService::class)->announce($group, $due['phase'], $due['resume']);
        $this->assertSame(VacationResumeService::STATUS_SENT, $result['status']);
        $this->assertContains('dm', $result['channels']);
        $this->assertContains('email', $result['channels']);
        $this->assertContains('group_chat', $result['channels']);

        Queue::assertPushed(SendMessengerAlerts::class);
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job): bool => $job->chatId === '-10089');
        Mail::assertQueued(VacationResumeMail::class);

        // Дедуп: повторный запуск в тот же слот — no-op (новых отправок нет,
        // сумма по ростеру остаётся 5 ЛС).
        $second = app(VacationResumeService::class)->announce($group, $due['phase'], $due['resume']);
        $this->assertSame(VacationResumeService::STATUS_DEDUP, $second['status']);
        Queue::assertPushed(SendMessengerAlerts::class, 5);
    }

    public function test_t1_wave_fires_one_day_before_resume(): void
    {
        Carbon::setTestNow('2026-10-16 20:05:00');
        $group = $this->seedGroup(pauseDays: 120, resumeDay: '2026-10-17');

        $due = app(VacationResumeService::class)->duePhase($group, today());
        $this->assertNotNull($due);
        $this->assertSame(VacationResumeNotice::PHASE_T1, $due['phase']);
    }

    public function test_announce_skipped_outside_t7_t1_window(): void
    {
        Carbon::setTestNow('2026-10-12 20:05:00');
        $group = $this->seedGroup(pauseDays: 120, resumeDay: '2026-10-17');

        $this->assertNull(app(VacationResumeService::class)->duePhase($group, today()));
    }

    public function test_text_carries_range_count_calendar_and_ics(): void
    {
        Carbon::setTestNow('2026-10-10 20:05:00');
        $group = $this->seedGroup(pauseDays: 120, resumeDay: '2026-10-17', futureRows: 36);
        $service = app(VacationResumeService::class);
        $resume = $service->resumeRow($group);

        $text = $service->resumeText($group, $resume, $group->users()->first());

        $this->assertStringContainsString('Занятия возобновляются', $text);
        $this->assertStringContainsString('17.10.2026', $text);
        $this->assertStringContainsString('36 занятий', $text);
        $this->assertStringContainsString('19.06.2027', $text);
        $this->assertStringContainsString('календарь', $text);
        $this->assertStringContainsString('ICS', $text);
    }

    public function test_attendance_alert_below_50_percent(): void
    {
        Carbon::setTestNow('2026-10-17 23:00:00');
        $group = $this->seedGroup(pauseDays: 120, resumeDay: '2026-10-17');
        // Опорная строка — занятие, которое уже началось сегодня (17.10 09:00).
        $todayRow = $group->schedules()->whereDate('start', '2026-10-17')->firstOrFail();
        $roster = $group->users()->get();

        // Явка 2 из 5 = 40% < 50%.
        foreach ($roster->take(2) as $user) {
            $todayRow->attendances()->create([
                'user_id' => $user->id,
                'zoom_participant_uuid' => 'uuid-'.$user->id,
                'name' => $user->name,
                'email' => $user->email,
                'joined_at' => now(),
                'duration_seconds' => 3600,
            ]);
        }

        config(['services.telegram.curators_chat_id' => '-100curators']);
        $alerts = app(VacationResumeService::class)->attendanceAlerts();

        $this->assertSame(1, $alerts);
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn ($job): bool => $job->chatId === '-100curators');

        // Дедуп: второй прогон молчит.
        $this->assertSame(0, app(VacationResumeService::class)->attendanceAlerts());
    }

    public function test_attendance_alert_silent_at_50_percent_and_above(): void
    {
        Carbon::setTestNow('2026-10-17 23:00:00');
        $group = $this->seedGroup(pauseDays: 120, resumeDay: '2026-10-17');
        $todayRow = $group->schedules()->whereDate('start', '2026-10-17')->firstOrFail();
        $roster = $group->users()->get();

        foreach ($roster->take(3) as $user) { // 3 из 5 = 60%
            $todayRow->attendances()->create([
                'user_id' => $user->id,
                'zoom_participant_uuid' => 'uuid-'.$user->id,
                'name' => $user->name,
                'email' => $user->email,
                'joined_at' => now(),
                'duration_seconds' => 3600,
            ]);
        }

        $summary = app(ClassAttendanceService::class)->forSchedule($todayRow)['summary'];
        $this->assertSame(3, (int) $summary['present']);

        $this->assertSame(0, app(VacationResumeService::class)->attendanceAlerts());
    }

    public function test_billing_block_reminder_paused_on_vacation_group(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');
        $group = Group::create(['name' => 'Гр. 89 каникулы', 'telegram_chat_id' => '-10089', 'is_on_vacation' => true]);
        // 4-е занятие блока закончилось вчера, следующее живое существует —
        // без флага ушло бы напоминание об оплате.
        Schedule::create(['title' => 'Занятие (#4, 31.07.26)', 'start' => '2026-07-31 09:00:00', 'end' => '2026-07-31 11:00:00', 'group_id' => $group->id]);
        Schedule::create(['title' => 'Занятие (#5, 07.08.26)', 'start' => '2026-08-07 09:00:00', 'end' => '2026-08-07 11:00:00', 'group_id' => $group->id]);

        app(SlotNoticeService::class)->blockPaymentReminders();

        Queue::assertNotPushed(SendZapisiBotMessageJob::class);
    }

    public function test_billing_block_reminder_fires_when_not_on_vacation(): void
    {
        Carbon::setTestNow('2026-08-01 12:00:00');
        config(['features.telegram_zapisi_bot' => true]);
        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('expire')->once();
        $group = Group::create(['name' => 'Гр. 90 работает', 'telegram_chat_id' => '-10090']);
        Schedule::create(['title' => 'Занятие (#4, 31.07.26)', 'start' => '2026-07-31 09:00:00', 'end' => '2026-07-31 11:00:00', 'group_id' => $group->id]);
        Schedule::create(['title' => 'Занятие (#5, 07.08.26)', 'start' => '2026-08-07 09:00:00', 'end' => '2026-08-07 11:00:00', 'group_id' => $group->id]);

        app(SlotNoticeService::class)->blockPaymentReminders();

        Queue::assertPushed(SendZapisiBotMessageJob::class);
    }

    public function test_soft_delete_phantoms_dry_run_then_apply_group89_shape(): void
    {
        Carbon::setTestNow('2026-10-10 21:00:00');
        $group = Group::create(['name' => 'Грамматика по Кочергиной гр.57']);

        // Фантомный шлейф #21–#38: 18 прошедших строк 06.06–10.10.26.
        $phantomStart = Carbon::parse('2026-06-06 09:00:00');
        for ($n = 21; $n <= 38; $n++) {
            Schedule::create([
                'title' => "Занятие #{$n}",
                'start' => $phantomStart->copy()->addWeeks($n - 21),
                'end' => $phantomStart->copy()->addWeeks($n - 21)->addHours(2),
                'group_id' => $group->id,
            ]);
        }

        // 36 живых строк #39–#74: субботы 09:00, 17.10.2026–19.06.2027.
        $liveStart = Carbon::parse('2026-10-17 09:00:00');
        for ($n = 39; $n <= 74; $n++) {
            Schedule::create([
                'title' => "Занятие #{$n}",
                'start' => $liveStart->copy()->addWeeks($n - 39),
                'end' => $liveStart->copy()->addWeeks($n - 39)->addHours(2),
                'group_id' => $group->id,
            ]);
        }

        $this->assertSame(54, $group->schedules()->count());

        // Dry-run (по умолчанию): показывает ровно 18 строк, ничего не удаляет.
        $this->artisan('schedule:soft-delete-phantoms', ['group' => (string) $group->id])
            ->expectsOutputToContain('кандидатов на soft-delete в окне — 18')
            ->assertSuccessful();
        $this->assertSame(54, $group->schedules()->count(), 'dry-run не удаляет.');

        // --apply: удаляет те же 18, живых остаётся 36.
        $this->artisan('schedule:soft-delete-phantoms', ['group' => (string) $group->id, '--apply' => true])
            ->expectsOutputToContain('кандидатов на soft-delete в окне — 18')
            ->assertSuccessful();

        $this->assertSame(36, $group->schedules()->count(), 'Живых строк группы 89 = 36.');
        $this->assertSame(54, $group->schedules()->withTrashed()->count(), 'Soft-delete обратим.');

        // Гибридный гейт после чистки: прошлых живых строк нет, пауза не
        // вычисляется — каскад открывает снятие флага каникул (ритуал
        // решения: MG ставит флаг, при подтверждении даты снимает).
        Carbon::setTestNow('2026-10-10 20:05:00');
        $group->refresh();
        $group->update(['is_on_vacation' => true]);
        $group->update(['is_on_vacation' => false]);

        $due = app(VacationResumeService::class)->duePhase($group, today());
        $this->assertNotNull($due, 'Флаг снят на этой неделе + до возобновления 7 дней — волна t7.');
        $this->assertSame(VacationResumeNotice::PHASE_T7, $due['phase']);
    }

    /**
     * Группа с прошедшей строкой за $pauseDays до возобновления и будущими
     * строками по субботам + ростер из 5 студентов.
     */
    private function seedGroup(int $pauseDays, string $resumeDay, int $futureRows = 36): Group
    {
        $group = Group::create(['name' => 'Грамматика по Кочергиной гр.57', 'telegram_chat_id' => '-10089']);

        $resume = Carbon::parse($resumeDay.' 09:00:00');
        Schedule::create([
            'title' => 'Занятие #20',
            'start' => $resume->copy()->subDays($pauseDays),
            'end' => $resume->copy()->subDays($pauseDays)->addHours(2),
            'group_id' => $group->id,
        ]);
        for ($i = 0; $i < $futureRows; $i++) {
            Schedule::create([
                'title' => 'Занятие #'.($i + 21),
                'start' => $resume->copy()->addWeeks($i),
                'end' => $resume->copy()->addWeeks($i)->addHours(2),
                'group_id' => $group->id,
            ]);
        }

        for ($i = 0; $i < 5; $i++) {
            $user = User::create([
                'name' => 'Студент '.($i + 1),
                'email' => "student{$i}@example.com",
                'telegram_id' => 900 + $i,
                'password' => 'password',
            ]);
            $user->activeGroups()->attach($group->id);
        }

        return $group->refresh();
    }
}
