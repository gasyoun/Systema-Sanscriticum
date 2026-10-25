<?php

declare(strict_types=1);

namespace Tests\Feature\Payments;

use App\Models\Course;
use App\Models\Group;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H6395 lever 2 (GTD WIC) — доказательство, что флаг ДОХОДИТ до контроллера.
 *
 * Ровно этого не хватало до находки: ClaimTrustPolicy существовал, был
 * покрыт тестами и не вызывался ниоткуда. Здесь один и тот же фикстур
 * (аккаунт 30 дней, ни одного платежа) прогоняется через РЕАЛЬНЫЙ POST
 * заявки дважды: флаг OFF ⇒ paid (легаси 7 суток), флаг ON + порог канала
 * 1000 ч ⇒ pending. Разное состояние платежа на одном фикстуре = шов живой,
 * а не декоративный.
 */
final class ClaimTrustGateWiringTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
        Storage::fake('local');
        Http::fake();

        Config::set('features.claim_trust_policy', false);
        Config::set('services.paypal.enabled', true);
        Config::set('services.paypal.me_link', 'https://www.paypal.com/paypalme/school');
        Config::set('services.bank_claim.enabled', true);
        Config::set('services.admin.email', 'admin@example.test');
    }

    private function tariff(string $groupName): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::create(['name' => $groupName]);
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->block(1)->create(['price' => 4800]);
    }

    /** Аккаунт 30 дней, без единого платежа — «устоявшийся» по обоим правилам. */
    private function establishedUser(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();

        return $user->refresh();
    }

    private function latestPayment(User $user, string $provider): Payment
    {
        return Payment::where('user_id', $user->id)
            ->where('provider', $provider)
            ->latest('id')
            ->firstOrFail();
    }

    // ---------------------------------------------------------------- PayPal claim

    public function test_paypal_claim_trusts_a_thirty_day_account_when_the_flag_is_off(): void
    {
        $tariff = $this->tariff('WIC paypal OFF');
        $user = $this->establishedUser();

        $this->actingAs($user)
            ->post('/paypal/'.$tariff->id, [
                'foreign_amount' => 40,
                'foreign_currency' => 'EUR',
                'paypal_payer' => 'payer@example.test',
                'paid_on' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $payment = $this->latestPayment($user, Payment::PROVIDER_PAYPAL);

        $this->assertSame('paid', $payment->status, 'OFF: легаси-правило 7 суток доверяет 30-дневному аккаунту');
        $this->assertTrue((bool) ($payment->claim_meta['auto_trusted'] ?? false));
    }

    public function test_paypal_claim_obeys_the_channel_threshold_when_the_flag_is_on(): void
    {
        Config::set('features.claim_trust_policy', true);
        Config::set('services.paypal.trust_min_account_age_hours', 1000);

        $tariff = $this->tariff('WIC paypal ON');
        $user = $this->establishedUser();

        $this->actingAs($user)
            ->post('/paypal/'.$tariff->id, [
                'foreign_amount' => 40,
                'foreign_currency' => 'EUR',
                'paypal_payer' => 'payer@example.test',
                'paid_on' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $payment = $this->latestPayment($user, Payment::PROVIDER_PAYPAL);

        $this->assertSame(
            'pending',
            $payment->status,
            'ON: 720 ч < 1000 ч ⇒ ручная сверка, значит порог канала реально доехал до контроллера',
        );
        $this->assertFalse((bool) ($payment->claim_meta['auto_trusted'] ?? false));
        $this->assertSame(0, $user->fresh()->groups()->count(), 'доступ не открыт');
    }

    // ----------------------------------------------------------------- Bank claim

    public function test_bank_claim_obeys_the_channel_threshold_when_the_flag_is_on(): void
    {
        Config::set('features.claim_trust_policy', true);
        Config::set('services.bank_claim.trust_min_account_age_hours', 1000);

        $tariff = $this->tariff('WIC bank ON');
        $user = $this->establishedUser();

        $this->actingAs($user)
            ->post('/bank/'.$tariff->id, [
                'foreign_amount' => '90',
                'foreign_currency' => 'EUR',
                'sender_name' => 'WIC SENDER',
                'paid_on' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $payment = $this->latestPayment($user, Payment::PROVIDER_BANK_SEPA);

        $this->assertSame('pending', $payment->status, 'ON: банковский канал читает СВОЙ порог');
        $this->assertFalse((bool) ($payment->claim_meta['auto_trusted'] ?? false));
    }

    public function test_bank_claim_still_trusts_the_same_account_when_the_flag_is_off(): void
    {
        $tariff = $this->tariff('WIC bank OFF');
        $user = $this->establishedUser();

        $this->actingAs($user)
            ->post('/bank/'.$tariff->id, [
                'foreign_amount' => '90',
                'foreign_currency' => 'EUR',
                'sender_name' => 'WIC SENDER',
                'paid_on' => now()->subDay()->toDateString(),
            ])
            ->assertRedirect();

        $payment = $this->latestPayment($user, Payment::PROVIDER_BANK_SEPA);

        $this->assertSame('paid', $payment->status, 'OFF: поведение мержа — прежнее');
        $this->assertTrue((bool) ($payment->claim_meta['auto_trusted'] ?? false));
    }
}
