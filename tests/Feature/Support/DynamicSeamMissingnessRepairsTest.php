<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Models\Schedule;
use App\Models\User;
use App\Services\TelegramSupport\TelegramSupportSyncService;
use App\Support\Roles;
use App\Support\ScheduleFailureSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * H5298 — per-defect regression tests for the three repaired DYNAMIC
 * (non-command) missingness seams found in wave 2, outside the eight
 * command-oriented seams censused by H5061:
 *
 *  1. ScheduleFailureSignal::report — the Filament admin-notify branch was a
 *     SILENT return when no super_admin/admin/accountant recipient existed;
 *     must now warn loudly (not_supported), independent of the Log::critical
 *     call that already fires unconditionally.
 *  2. TechnicalIssueNotifier::newTechnicalIssue — same silent-skip class when
 *     no assignee/admin recipient exists for the technical-issue duty pager.
 *  3. ScheduleObserver::sendToN8n — an unconfigured webhook silently sent
 *     nothing with no record distinguishing "off by design" from a future
 *     accidental misconfiguration.
 */
final class DynamicSeamMissingnessRepairsTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_failure_signal_warns_when_no_recipient_exists(): void
    {
        // Deliberately no super_admin/admin/accountant user in the fixture.
        Log::spy();

        ScheduleFailureSignal::report('promises:expire');

        Log::shouldHaveReceived('critical')->once()->withArgs(
            fn (string $m, array $ctx): bool => $m === 'schedule.money_command_failed' && $ctx['command'] === 'promises:expire'
        );

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $m, array $ctx): bool => $m === 'schedule.money_command_failure_signal_not_supported'
                && $ctx['state'] === 'not_supported'
                && $ctx['command'] === 'promises:expire'
        );
    }

    public function test_schedule_failure_signal_does_not_warn_when_a_recipient_exists(): void
    {
        User::factory()->create(['role' => Roles::ADMIN]);
        Log::spy();

        ScheduleFailureSignal::report('promises:expire');

        Log::shouldHaveReceived('critical')->once();
        Log::shouldNotHaveReceived('warning');
    }

    public function test_technical_issue_notifier_warns_when_no_recipient_exists(): void
    {
        // No support_tech.assignee_user_id and no super_admin/admin user.
        config([
            'support_tech.assignee_user_id' => null,
            'support_tech.keywords' => ['кабинет', 'zoom', 'доступ', 'пароль'],
            'services.telegram_support.enabled' => false,
            'services.telegram_support.tech_assignee_user_id' => null,
        ]);
        Log::spy();

        app(TelegramSupportSyncService::class)->syncNormalizedMessages([
            [
                'telegram_chat_id' => -100199,
                'telegram_message_id' => 1,
                'telegram_user_id' => 19001,
                'direction' => 'incoming',
                'text' => 'не открывается кабинет со вчера',
                'sent_at' => now()->toDateTimeString(),
                'chat_type' => 'supergroup',
                'contact_name' => 'Тест',
            ],
        ]);

        $this->assertSame(0, \DB::table('notifications')->count());
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $m, array $ctx): bool => $m === 'technical_issue_notifier.not_supported' && $ctx['state'] === 'not_supported'
        );
    }

    public function test_schedule_observer_logs_not_supported_when_webhook_unconfigured(): void
    {
        config(['services.n8n.schedule_sheet_webhook' => null]);
        Http::fake();
        Log::spy();

        $schedule = Schedule::create([
            'title' => 'Санскрит, занятие 1',
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
        ]);

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->once()->withArgs(
            fn (string $m, array $ctx): bool => $m === 'schedule_observer.n8n_not_supported'
                && $ctx['state'] === 'not_supported'
                && $ctx['schedule_id'] === $schedule->id
                && $ctx['action'] === 'create'
        );
    }

    public function test_schedule_observer_stays_quiet_on_the_log_when_webhook_configured(): void
    {
        config(['services.n8n.schedule_sheet_webhook' => 'https://n8n.example/webhook/schedule']);
        Http::fake(['n8n.example/*' => Http::response(['ok' => true])]);
        Log::spy();

        Schedule::create([
            'title' => 'Санскрит, занятие 1',
            'start' => now()->addDay(),
            'end' => now()->addDay()->addHour(),
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://n8n.example/webhook/schedule');
        Log::shouldNotHaveReceived('info');
    }
}
