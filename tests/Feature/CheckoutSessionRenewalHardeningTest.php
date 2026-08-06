<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * H1396 §§2–4 — the checkout page survives its own session (the non-money half).
 *
 * §2 A logged-in student whose session lapses before submit lands at payment.create
 *    unauthenticated. The form they saw had no guest identity fields (@guest only),
 *    so the guest-required validation used to fire errors on fields they never saw,
 *    then hard-refuse their own email. Route them back through login instead.
 * §3 The bank return identified the order by session (auth id + latest payment) —
 *    which fails when the return lands in a different cookie jar (in-app WebView) and
 *    picks the wrong order when two are pending. A signed payment id fixes both.
 * §4 /csrf-token was the only web-group route with no throttle — a cookie-less hit
 *    writes a fresh session file with SESSION_DRIVER=file.
 *
 * Each behaviour is behind a default-OFF flag (except §4's throttle, which is the
 * same hardening every neighbouring route already carries); the flag-OFF parity
 * assertions pin today's behaviour.
 */
class CheckoutSessionRenewalHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        MarketingSetting::flushCached();

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

    // ─────────────────────────────── §2 ───────────────────────────────

    /**
     * Lapsed session: unauthenticated submit carrying the checkout_authed marker is
     * bounced to login with the checkout as the intended return, and NO payment is
     * created and NO guest-field validation errors are shown.
     *
     * @test
     */
    public function lapsed_authed_session_is_routed_back_through_login(): void
    {
        config(['features.checkout_session_lapse_relogin' => true]);
        $tariff = $this->tariff();

        // No actingAs(): the session has lapsed, so the submit arrives as a guest.
        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'checkout_authed' => '1',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', route('checkout.show', $tariff->id))
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Payment::count(), 'a lapsed session must not create a payment');
    }

    /**
     * Flag OFF: today's behaviour — the same lapsed submit falls through to the
     * guest-required validation and errors on the unseen fields (no login bounce).
     *
     * @test
     */
    public function with_relogin_flag_off_lapsed_session_hits_guest_validation_as_today(): void
    {
        config(['features.checkout_session_lapse_relogin' => false]);
        $tariff = $this->tariff();

        $response = $this->from(route('checkout.show', $tariff->id))
            ->post(route('payment.create'), [
                'tariff_id' => $tariff->id,
                'checkout_authed' => '1',
            ]);

        $response->assertSessionHasErrors(['name', 'surname', 'city', 'email']);
        $response->assertRedirect(route('checkout.show', $tariff->id));
        $this->assertSame(0, Payment::count());
    }

    /**
     * A genuine guest (no checkout_authed marker) is NOT caught by the §2 gate even
     * with the flag on — they submit the guest form and proceed to the bank.
     *
     * @test
     */
    public function genuine_guest_is_not_bounced_to_login(): void
    {
        config(['features.checkout_session_lapse_relogin' => true]);
        $tariff = $this->tariff();

        $this->post(route('payment.create'), [
            'tariff_id' => $tariff->id,
            'name' => 'Иван',
            'surname' => 'Иванов',
            'city' => 'Москва',
            'email' => 'guest-new@example.com',
        ])->assertRedirect('https://pay.tochka.com/redirect/abc');

        $this->assertSame(1, Payment::count());
    }

    // ─────────────────────────────── §3 ───────────────────────────────

    /**
     * A signed return URL identifies the exact order even with NO session — the
     * cookie-jar-loss case. The success page shows that payment, not a guest wall.
     *
     * @test
     */
    public function signed_return_url_identifies_order_without_a_session(): void
    {
        config(['features.checkout_signed_return_url' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'status' => 'paid',
            'tariff' => 'full',
        ]);

        // No actingAs(): the return landed in a different cookie jar.
        $url = URL::signedRoute('payment.success', ['payment' => $payment->id]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertViewHas('confirmed', true);
        $this->assertSame($payment->id, $response->viewData('payment')->id);
    }

    /**
     * With two pending orders and no session, the signed id resolves to the order the
     * bank returned on — NOT merely the latest. This is the wrong-order half of §3.
     *
     * @test
     */
    public function signed_id_resolves_the_right_order_not_the_latest(): void
    {
        config(['features.checkout_signed_return_url' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $first = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 3000, 'status' => 'pending', 'tariff' => 'full',
        ]);
        // A second, later pending order for the same user.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 5000, 'status' => 'pending', 'tariff' => 'full',
        ]);

        $url = URL::signedRoute('payment.success', ['payment' => $first->id]);

        $response = $this->actingAs($user)->get($url);

        $this->assertSame($first->id, $response->viewData('payment')->id, 'signed id must win over latest()');
    }

    /**
     * Flag OFF: the signed id is ignored and the legacy session path is used — a
     * signed URL hit by a guest resolves to no payment, exactly as before §3.
     *
     * @test
     */
    public function with_signed_flag_off_signed_id_is_ignored(): void
    {
        config(['features.checkout_signed_return_url' => false]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 5000, 'status' => 'paid', 'tariff' => 'full',
        ]);

        $url = URL::signedRoute('payment.success', ['payment' => $payment->id]);

        // Guest + flag off → no session, signed id ignored → no payment resolved.
        $response = $this->get($url);
        $response->assertOk();
        $this->assertNull($response->viewData('payment'));
    }

    /**
     * A tampered/invalid signature falls back to the session path rather than trusting
     * the id — a forged payment param must not surface someone else's order.
     *
     * @test
     */
    public function invalid_signature_is_not_trusted(): void
    {
        config(['features.checkout_signed_return_url' => true]);
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $victim = Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 5000, 'status' => 'paid', 'tariff' => 'full',
        ]);

        // Unsigned URL carrying a payment id — no valid signature.
        $forged = route('payment.success', ['payment' => $victim->id]);

        $response = $this->get($forged);
        $response->assertOk();
        $this->assertNull($response->viewData('payment'), 'an unsigned payment param must not resolve an order');
    }

    // ─────────────────────────────── §4 ───────────────────────────────

    /**
     * /csrf-token is throttled (30/min). The 31st cookie-less hit in a window is
     * rejected, closing the unauthenticated session-file-fill primitive.
     *
     * @test
     */
    public function csrf_token_endpoint_is_throttled(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->get(route('csrf.token'))->assertOk();
        }

        $this->get(route('csrf.token'))->assertStatus(429);
    }
}
