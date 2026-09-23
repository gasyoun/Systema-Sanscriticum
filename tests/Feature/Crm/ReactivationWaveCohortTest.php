<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Enums\ReactivationWaveTemplate;
use App\Models\DebtWinBackAttempt;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\SuppressedEmail;
use App\Models\User;
use App\Services\Crm\Reactivation\ReactivationWaveCohort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * H5288 — волна реактивации A: когорта, каналы, исключения. Главный пин —
 * сухой прогон НИЧЕГО не отправляет и не пишет в базу.
 */
class ReactivationWaveCohortTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Абсолютных дат в фикстурах нет — всё считается от закреплённого «сейчас».
        Carbon::setTestNow('2026-09-23 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $attributes */
    private function payer(User $user, int $daysAgo, array $attributes = []): Payment
    {
        $at = Carbon::now()->subDays($daysAgo);

        // withoutEvents: PaymentObserver раздаёт доступы — когорте они не нужны.
        $payment = Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $user->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
            'is_conditional' => false,
        ], $attributes)));

        $payment->forceFill(['first_paid_at' => $at, 'created_at' => $at])->saveQuietly();

        return $payment->refresh();
    }

    private function cohort(): ReactivationWaveCohort
    {
        return app(ReactivationWaveCohort::class);
    }

    /** @test */
    public function splits_the_cohort_into_lapsed_and_non_continuers(): void
    {
        $recent = User::factory()->create(['email' => 'recent@example.com', 'telegram_id' => null]);
        $this->payer($recent, 200);

        $old = User::factory()->create(['email' => 'old@example.com', 'telegram_id' => 900001]);
        $this->payer($old, 800);

        $census = $this->cohort()->evaluate();

        $segments = $census->segmentCounts();
        $this->assertSame(1, $segments[ReactivationWaveCohort::SEGMENT_NON_CONTINUER] ?? 0);
        $this->assertSame(1, $segments[ReactivationWaveCohort::SEGMENT_LAPSED] ?? 0);
        $this->assertSame(0, $census->toArray()['messages_sent']);
    }

    /** @test */
    public function channel_split_prefers_the_telegram_bot_over_email(): void
    {
        $bot = User::factory()->create(['email' => 'bot@example.com', 'telegram_id' => 900002]);
        $this->payer($bot, 400);

        $mail = User::factory()->create(['email' => 'mail@example.com', 'telegram_id' => null]);
        $this->payer($mail, 400);

        $channels = $this->cohort()->evaluate()->channelCounts();

        $this->assertSame(1, $channels[ReactivationWaveCohort::CHANNEL_TELEGRAM] ?? 0);
        $this->assertSame(1, $channels[ReactivationWaveCohort::CHANNEL_EMAIL] ?? 0);
    }

    /** @test */
    public function active_payers_opt_outs_and_blocked_cards_are_excluded_with_a_reason(): void
    {
        $active = User::factory()->create(['email' => 'active@example.com']);
        $this->payer($active, 10);

        $unreliable = User::factory()->create(['email' => 'bad@example.com', 'is_unreliable' => true]);
        $this->payer($unreliable, 400);

        $doNotContact = User::factory()->create(['email' => 'quiet@example.com', 'note' => '12-01-2026 просил не писать']);
        $this->payer($doNotContact, 400);

        $suppressed = User::factory()->create(['email' => 'bounced@example.com', 'telegram_id' => null]);
        $this->payer($suppressed, 400);
        SuppressedEmail::query()->create(['email' => 'bounced@example.com', 'reason' => 'hard_bounce']);

        $noConsent = User::factory()->create([
            'email' => 'noconsent@example.com',
            'telegram_id' => null,
            'wants_email_announcements' => false,
        ]);
        $this->payer($noConsent, 400);

        $placeholder = User::factory()->create(['email' => 'ghost@no-email.com', 'telegram_id' => null]);
        $this->payer($placeholder, 400);

        $census = $this->cohort()->evaluate();
        $reasons = $census->exclusionCounts();

        $this->assertSame('active_payer', $census->excluded[$active->id]);
        $this->assertSame('blocked_or_staff', $census->excluded[$unreliable->id]);
        $this->assertSame('do_not_contact', $census->excluded[$doNotContact->id]);
        $this->assertSame('suppressed_email', $census->excluded[$suppressed->id]);
        $this->assertSame('no_channel', $census->excluded[$noConsent->id]);
        $this->assertSame('no_channel', $census->excluded[$placeholder->id]);
        $this->assertSame([], $census->sendList);
        $this->assertSame(6, array_sum($reasons));
    }

    /** @test */
    public function a_recent_win_back_attempt_keeps_a_student_out_of_the_wave(): void
    {
        $user = User::factory()->create(['email' => 'written@example.com', 'telegram_id' => 900003]);
        $this->payer($user, 400);
        DebtWinBackAttempt::query()->create([
            'user_id' => $user->id,
            'template_id' => MessageTemplate::factory()->create()->id,
            'channel' => 'tg',
            'sent_at' => Carbon::now()->subDays(3),
        ]);

        $census = $this->cohort()->evaluate();

        $this->assertSame('recently_contacted', $census->excluded[$user->id]);
    }

    /** @test */
    public function the_dry_run_command_writes_a_report_and_sends_nothing(): void
    {
        $user = User::factory()->create(['email' => 'report@example.com', 'telegram_id' => 900004]);
        $this->payer($user, 500);

        $path = 'storage/app/testing/reactivation_wave_a_dry_run.md';
        @unlink(base_path($path));

        $this->artisan('crm:reactivation-wave-dry-run', ['--write' => $path])
            ->expectsOutputToContain('Сухой прогон — отправлено сообщений: 0')
            ->assertExitCode(0);

        $report = (string) file_get_contents(base_path($path));
        $this->assertStringContainsString('| **Отправлено сообщений** | **0** |', $report);
        $this->assertStringContainsString(ReactivationWaveCohort::CHANNEL_TELEGRAM, $report);
        $this->assertStringContainsString(ReactivationWaveTemplate::Return->value, $report);
        // Сухой прогон не журналирует попыток — база чистая.
        $this->assertDatabaseCount('debt_win_back_attempts', 0);

        @unlink(base_path($path));
    }

    /** @test */
    public function both_templates_carry_the_three_lifts_and_no_urgency(): void
    {
        foreach (ReactivationWaveTemplate::cases() as $template) {
            $body = mb_strtolower($template->body());
            $this->assertStringContainsString('запис', $body, $template->value.': нет лифта «записи»');
            $this->assertStringContainsString('темп', $body, $template->value.': нет лифта «свой темп»');
            $this->assertStringContainsString('рассрочк', $body, $template->value.': нет лифта «рассрочка»');
            $this->assertSame(
                [],
                ReactivationWaveTemplate::urgencyHits($template->body().' '.$template->subject()),
                $template->value.': в тексте появилась искусственная срочность',
            );
        }
    }
}
