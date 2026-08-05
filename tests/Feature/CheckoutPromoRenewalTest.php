<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1396 §1 — the checkout page survives its own session.
 *
 * The applied promo used to live ONLY in the session. When the anti-419 refresh
 * minted a fresh (empty) session and remember-me re-authenticated the user,
 * `session('promo_code')` was gone at createPayment, `$promo` stayed null, and the
 * student was charged FULL PRICE while the button had shown the discounted total
 * (money-core, re-entry of H071 #13 through a different door).
 *
 * Fix: the code is carried in a hidden field and re-resolved authoritatively at
 * submit. If it re-resolves as no longer applicable, the charge is not silently
 * raised — the student sees the new total and must explicitly confirm it
 * (MG ruling 20-07-2026). Invariant: the student is never charged a total they
 * were not shown.
 */
class CheckoutPromoRenewalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

        // The guard is default-OFF in prod; the behaviour tests exercise it ON.
        // The parity test below flips it back OFF to pin today's behaviour.
        config(['features.checkout_promo_survives_session' => true]);

        Http::fake([
            'enter.tochka.com/*' => Http::response([
                'Data' => [
                    'paymentLink' => 'https://pay.tochka.com/redirect/abc',
                    'paymentLinkId' => 'tochka_tx_001',
                ],
            ], 200),
        ]);
    }

    private function tariff(int $price = 5000): Tariff
    {
        $course = Course::factory()->create();
        $group = Group::factory()->create();
        $course->groups()->attach($group->id);

        return Tariff::factory()->for($course)->create(['price' => $price]);
    }

    /**
     * Reproduction: promo carried in the hidden field survives a lost session and
     * still discounts. The session is deliberately NOT primed with promo_code —
     * that is exactly the state the renewal leaves behind. Red on the old code
     * (charged 5000), green after the fix (charged 4000).
     *
     * @test
     */
    public function promo_carried_in_hidden_field_survives_session_loss(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);
        PromoCode::create([
            'code' => 'MINUS20',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ]);

        // No withSession(): the promo lives only in the hidden field now.
        $this->actingAs($user)
            ->post(route('payment.create'), [
                'tariff_id' => $tariff->id,
                'promo_code' => 'MINUS20',
            ])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertEquals(4000, (float) $payment->amount, 'promo must survive session loss, not silently drop to full price');
    }

    /**
     * The ruled path: the carried code re-resolves as no longer applicable
     * (expired). The first submit must create NO payment and show the new total;
     * only after explicit confirmation is a payment created, at the full price the
     * confirmation screen showed.
     *
     * @test
     */
    public function lapsed_promo_requires_confirmation_then_charges_the_shown_full_price(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);
        PromoCode::create([
            'code' => 'EXPIRED',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
            'expires_at' => now()->subDay(),
        ]);

        // First submit — carried code has lapsed. No payment; a confirmation surface
        // stating the new total.
        $this->actingAs($user)
            ->post(route('payment.create'), [
                'tariff_id' => $tariff->id,
                'promo_code' => 'EXPIRED',
            ])
            ->assertOk()
            ->assertViewIs('checkout.confirm-price')
            ->assertViewHas('newTotal', 5000.0);

        $this->assertSame(0, Payment::where('user_id', $user->id)->count(), 'no payment may be created before the student confirms the new total');

        // Explicit confirmation → payment at the shown full price.
        $this->actingAs($user)
            ->post(route('payment.create'), [
                'tariff_id' => $tariff->id,
                'promo_lapse_confirmed' => '1',
                'confirmed_total' => 5000,
            ])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertEquals(5000, (float) $payment->amount, 'the confirmed charge must equal the total shown on the confirmation screen');
    }

    /**
     * A code that is still valid at submit must NOT trigger the confirmation
     * interstitial — the happy path stays one click.
     *
     * @test
     */
    public function still_valid_carried_promo_does_not_trigger_confirmation(): void
    {
        $user = User::factory()->create();
        $tariff = $this->tariff(5000);
        PromoCode::create([
            'code' => 'MINUS20',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('payment.create'), [
                'tariff_id' => $tariff->id,
                'promo_code' => 'MINUS20',
            ])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');

        $this->assertEquals(4000, (float) Payment::where('user_id', $user->id)->firstOrFail()->amount);
    }

    /**
     * Flag-OFF parity: with the guard disabled, createPayment behaves exactly like
     * today — it reads the promo ONLY from the session and ignores the hidden field.
     * A carried code with no session promo therefore does not discount (this is the
     * unfixed behaviour the flag gates), and a session promo still applies as before.
     *
     * @test
     */
    public function with_guard_off_behaviour_is_unchanged_from_today(): void
    {
        config(['features.checkout_promo_survives_session' => false]);

        $user = User::factory()->create();
        $tariff = $this->tariff(5000);
        PromoCode::create([
            'code' => 'MINUS20',
            'type' => 'percent',
            'value' => 20,
            'is_active' => true,
        ]);

        // Hidden field carried, session empty → NOT applied when the flag is off.
        $this->actingAs($user)
            ->post(route('payment.create'), [
                'tariff_id' => $tariff->id,
                'promo_code' => 'MINUS20',
            ])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');
        $this->assertEquals(5000, (float) Payment::where('user_id', $user->id)->firstOrFail()->amount);

        // Session-based promo still discounts, exactly as before the change.
        Payment::query()->delete();
        $this->actingAs($user)
            ->withSession(['promo_code' => 'MINUS20'])
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect('https://pay.tochka.com/redirect/abc');
        $this->assertEquals(4000, (float) Payment::where('user_id', $user->id)->firstOrFail()->amount);
    }
}
