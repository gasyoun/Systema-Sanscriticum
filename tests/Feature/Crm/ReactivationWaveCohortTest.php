<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Enums\ReactivationWaveTemplate;
use App\Models\DebtWinBackAttempt;
use App\Models\Group;
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
        SuppressedEmail::query()->create([
            'email' => 'bounced@example.com',
            'reason' => 'hard_bounce',
            'suppressed_at' => Carbon::now()->subDays(30),
        ]);

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
    public function a_forming_roster_does_not_count_as_current_access(): void
    {
        $forming = User::factory()->create(['email' => 'forming@example.com', 'telegram_id' => 900010]);
        $this->payer($forming, 400);
        $forming->groups()->attach(Group::factory()->create(['status' => 'forming'])->id);

        $studying = User::factory()->create(['email' => 'studying@example.com', 'telegram_id' => 900011]);
        $this->payer($studying, 400);
        $studying->groups()->attach(Group::factory()->create(['status' => 'active'])->id);

        $census = $this->cohort()->evaluate();

        // Набор — это список приглашённых, а не доступ: такой ученик в волне.
        $this->assertSame([$forming->id], array_column($census->sendList, 'user_id'));
        $this->assertSame('active_payer', $census->excluded[$studying->id]);
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

    /**
     * H5821 (C1): подпись обоих текстов — фирменная, проекта, а не чужая.
     *
     * @test
     */
    public function templates_sign_with_the_house_name(): void
    {
        foreach (ReactivationWaveTemplate::cases() as $template) {
            $this->assertStringContainsString(
                'Общества ревнителей санскрита',
                $template->body(),
                $template->value.': подпись не фирменная',
            );
        }
    }

    /**
     * H5821 (C1): глубина сна пула уснувших и его каналы считаются отдельно
     * от непродолживших.
     *
     * @test
     */
    public function lapsed_pool_gets_dormancy_buckets_and_its_own_channel_split(): void
    {
        // Уснувшие: 500 дней (до 730) и 1200 дней (более 1095).
        $younger = User::factory()->create(['email' => 'y@example.com', 'telegram_id' => null]);
        $this->payer($younger, 500);
        $older = User::factory()->create(['email' => 'o@example.com', 'telegram_id' => 900020]);
        $this->payer($older, 1200);
        // Непродолживший: 200 дней — в пул 563 не попадает.
        $recent = User::factory()->create(['email' => 'r@example.com', 'telegram_id' => 900021]);
        $this->payer($recent, 200);

        $census = $this->cohort()->evaluate();

        $buckets = $census->lapsedDormancyBuckets((array) config('reactivation_wave.dormancy_bucket_edges'));
        $this->assertSame(1, $buckets['до 730 дней'] ?? 0, 'уснувший 500 дней должен лечь в первую корзину');
        $this->assertSame(1, $buckets['более 1095 дней'] ?? 0, 'уснувший 1200 дней должен лечь в последнюю корзину');
        $this->assertSame(2, array_sum($buckets), 'непродолживший не входит в пул уснувших');

        $lapsedChannels = $census->lapsedChannelCounts();
        $this->assertSame(1, $lapsedChannels[ReactivationWaveCohort::CHANNEL_EMAIL] ?? 0);
        $this->assertSame(1, $lapsedChannels[ReactivationWaveCohort::CHANNEL_TELEGRAM] ?? 0);
        $this->assertSame(2, array_sum($lapsedChannels));
    }

    /**
     * H5821 (C1): исключения считаются по сегментам — у пула уснувших своя
     * лестница причин.
     *
     * @test
     */
    public function exclusions_are_attributed_per_segment(): void
    {
        $lapsedNoChannel = User::factory()->create(['email' => 'ln@example.com', 'telegram_id' => null, 'wants_email_announcements' => false]);
        $this->payer($lapsedNoChannel, 800);

        $recentActive = User::factory()->create(['email' => 'ra@example.com']);
        $this->payer($recentActive, 30);

        $census = $this->cohort()->evaluate();
        $bySegment = $census->excludedCountsBySegment();

        $this->assertSame(1, $bySegment[ReactivationWaveCohort::SEGMENT_LAPSED]['no_channel'] ?? 0);
        $this->assertSame(1, $bySegment[ReactivationWaveCohort::SEGMENT_NON_CONTINUER]['active_payer'] ?? 0);
        $this->assertArrayNotHasKey('active_payer', $bySegment[ReactivationWaveCohort::SEGMENT_LAPSED] ?? []);
    }
}
