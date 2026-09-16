<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H5024: referral:weekly-graph — понедельничный снимок графа рефералов в чат
 * онбординга. Датированный ноль уходит как результат; без чата — no-op.
 */
class ReferralGraphWeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    private function event(): ?Event
    {
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'referral:weekly-graph')) {
                return $event;
            }
        }

        return null;
    }

    /** @test */
    public function weekly_graph_is_registered_on_monday_after_onboarding_digest(): void
    {
        $this->assertNotNull($this->event(), 'referral:weekly-graph не найден в расписании Kernel');
        // weeklyOn(1, '09:35') → «35 9 * * 1», через 5 минут после onboarding:weekly-digest.
        $this->assertSame('35 9 * * 1', $this->event()?->expression);
    }

    /** @test */
    public function dated_zero_is_reported_and_dispatched_to_onboarding_chat(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-21 09:35:00');
        config(['services.telegram.onboarding_chat_id' => '-100123', 'partner.enabled' => true]);

        $this->artisan('referral:weekly-graph')
            ->expectsOutputToContain('всего: 0, за 7 дней: 0')
            ->expectsOutputToContain('Ноль на 21.09.2026 — зафиксирован.')
            ->assertExitCode(0);

        Queue::assertPushed(SendTelegramChatMessageJob::class, function (SendTelegramChatMessageJob $job): bool {
            return $job->chatId === '-100123'
                && str_contains($job->text, '<b>Граф рефералов</b> — на 21.09.2026')
                && str_contains($job->text, 'включена');
        });

        Carbon::setTestNow();
    }

    /** @test */
    public function counts_edges_referrers_and_rewards_within_the_week(): void
    {
        Queue::fake();
        Carbon::setTestNow('2026-09-21 09:35:00');
        config(['services.telegram.onboarding_chat_id' => '-100123']);

        $patron = User::factory()->create();
        $oldReferral = User::factory()->create(['referred_by' => $patron->id, 'created_at' => now()->subDays(30)]);
        $newReferral = User::factory()->create(['referred_by' => $patron->id, 'created_at' => now()->subDays(2)]);
        $otherPatron = User::factory()->create();
        User::factory()->create(['referred_by' => $otherPatron->id, 'created_at' => now()->subDays(1)]);

        ReferralReward::create(['referrer_id' => $patron->id, 'referred_id' => $oldReferral->id, 'amount' => 500]);
        ReferralReward::create(['referrer_id' => $patron->id, 'referred_id' => $newReferral->id, 'amount' => 500]);
        ReferralReward::where('referred_id', $oldReferral->id)->update(['created_at' => now()->subDays(20)]);

        $this->artisan('referral:weekly-graph')
            ->expectsOutputToContain('всего: 3, за 7 дней: 2')
            ->expectsOutputToContain('Рефереров (кто привёл хотя бы одного): 2')
            ->expectsOutputToContain('всего 2, за 7 дней: 1')
            ->doesntExpectOutputToContain('зафиксирован')
            ->assertExitCode(0);

        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);

        Carbon::setTestNow();
    }

    /** @test */
    public function dry_run_and_missing_chat_send_nothing(): void
    {
        Queue::fake();
        config(['services.telegram.onboarding_chat_id' => '-100123']);

        $this->artisan('referral:weekly-graph', ['--dry' => true])
            ->expectsOutputToContain('--dry: в чат не отправлено.')
            ->assertExitCode(0);

        config(['services.telegram.onboarding_chat_id' => null]);

        $this->artisan('referral:weekly-graph')
            ->expectsOutputToContain('TELEGRAM_ONBOARDING_CHAT_ID не задан')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
    }
}
