<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use App\Services\Payments\ClaimTrustGate;
use App\Services\Payments\ClaimTrustPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * H6395 lever 2 (GTD WIC) — шов ClaimTrustGate.
 *
 * До этого предикат доверия заявки был скопирован в три контроллера, а
 * ClaimTrustPolicy не вызывался ниоткуда (0 % покрытия на мёртвом коде).
 * Здесь зафиксировано: OFF — прод-инертная легаси-семантика (7 сут), ON —
 * политика с порогом канала, и при пустом env ON не меняет поведение.
 */
final class ClaimTrustGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Флаг выключен, как в проде до отдельного ops-шага.
        Config::set('features.claim_trust_policy', false);
        Config::set(ClaimTrustGate::PAYPAL.'.trust_min_account_age_hours', ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS);
        Config::set(ClaimTrustGate::BANK_CLAIM.'.trust_min_account_age_hours', ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS);
        Config::set(ClaimTrustGate::GASUNS_PAY.'.trust_min_account_age_hours', ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS);
    }

    private function gate(): ClaimTrustGate
    {
        return new ClaimTrustGate;
    }

    private function agedUser(?int $daysAgo): User
    {
        $user = User::factory()->create();

        if ($daysAgo !== null) {
            $user->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
        }

        return $user->refresh();
    }

    private function paidUser(): User
    {
        $user = $this->agedUser(null);
        Payment::withoutEvents(fn (): Payment => Payment::create([
            'user_id' => $user->getKey(),
            'course_id' => Course::factory()->create()->getKey(),
            'amount' => 4800,
            'tariff' => 'prior',
            'status' => 'paid',
        ]));

        return $user;
    }

    // ------------------------------------------------ invariant that keeps ON == OFF

    public function test_policy_default_equals_the_legacy_seven_day_window(): void
    {
        $this->assertSame(
            User::CLAIM_TRUST_MIN_AGE_DAYS * 24,
            ClaimTrustPolicy::DEFAULT_MIN_ACCOUNT_AGE_HOURS,
            'дефолт политики обязан совпадать с легаси-окном, иначе включение флага молча меняет денежный контур',
        );
    }

    public function test_every_channel_namespace_is_a_real_config_path(): void
    {
        foreach ([ClaimTrustGate::PAYPAL, ClaimTrustGate::BANK_CLAIM, ClaimTrustGate::GASUNS_PAY] as $namespace) {
            $this->assertIsInt(
                config($namespace.'.trust_min_account_age_hours'),
                $namespace.' must carry trust_min_account_age_hours — a bare namespace would silently fall back',
            );
        }
    }

    // --------------------------------------------------------------- flag OFF (prod)

    public function test_off_uses_the_legacy_window_and_ignores_the_channel_threshold(): void
    {
        // Порог канала выставлен в 1000 ч, но при OFF он не читается вообще:
        // 30-дневный аккаунт доверяется по легаси-правилу 7 суток.
        Config::set(ClaimTrustGate::PAYPAL.'.trust_min_account_age_hours', 1000);

        $this->assertTrue($this->gate()->trusts($this->agedUser(30), ClaimTrustGate::PAYPAL));
        $this->assertFalse($this->gate()->trusts($this->agedUser(3), ClaimTrustGate::PAYPAL));
    }

    public function test_off_honours_a_prior_paid_payment(): void
    {
        $this->assertTrue($this->gate()->trusts($this->paidUser(), ClaimTrustGate::PAYPAL));
    }

    // ---------------------------------------------------------------- flag ON (dark)

    public function test_on_with_default_config_matches_the_off_path(): void
    {
        Config::set('features.claim_trust_policy', true);

        $this->assertTrue($this->gate()->trusts($this->agedUser(30), ClaimTrustGate::PAYPAL));
        $this->assertFalse($this->gate()->trusts($this->agedUser(3), ClaimTrustGate::PAYPAL));
        $this->assertTrue($this->gate()->trusts($this->paidUser(), ClaimTrustGate::PAYPAL));
    }

    public function test_on_reads_the_threshold_of_the_requested_channel(): void
    {
        Config::set('features.claim_trust_policy', true);
        Config::set(ClaimTrustGate::PAYPAL.'.trust_min_account_age_hours', 1000);
        Config::set(ClaimTrustGate::BANK_CLAIM.'.trust_min_account_age_hours', 24);

        $user = $this->agedUser(30); // 720 ч

        $this->assertFalse(
            $this->gate()->trusts($user, ClaimTrustGate::PAYPAL),
            'paypal: 720 ч < 1000 ч ⇒ не доверяем',
        );
        $this->assertTrue(
            $this->gate()->trusts($user, ClaimTrustGate::BANK_CLAIM),
            'bank_claim: 720 ч ≥ 24 ч ⇒ доверяем',
        );
    }

    public function test_on_still_honours_a_prior_paid_payment_regardless_of_age(): void
    {
        Config::set('features.claim_trust_policy', true);
        Config::set(ClaimTrustGate::PAYPAL.'.trust_min_account_age_hours', 100000);

        $this->assertTrue($this->gate()->trusts($this->paidUser(), ClaimTrustGate::PAYPAL));
    }

    // ------------------------------------------------------------------- guest path

    public function test_a_guest_is_never_trusted_in_either_mode(): void
    {
        $this->assertFalse($this->gate()->trusts(null, ClaimTrustGate::PAYPAL));

        Config::set('features.claim_trust_policy', true);
        $this->assertFalse($this->gate()->trusts(null, ClaimTrustGate::PAYPAL));
    }
}
