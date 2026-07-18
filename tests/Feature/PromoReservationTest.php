<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PromoReservationTest extends TestCase
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
                    'paymentLink' => 'https://pay.tochka.com/redirect/promo',
                    'paymentLinkId' => 'promo_reservation_tx',
                ],
            ]),
        ]);
    }

    public function test_promo_reservations_are_dark_by_default(): void
    {
        $this->assertFalse(config('features.checkout_promo_reservations'));
    }

    public function test_flag_off_preserves_payload_and_expiry_behavior(): void
    {
        [$tariff, $promo] = $this->tariffAndPromo();
        $user = User::factory()->create();

        $this->checkout($user, $tariff, $promo)->assertRedirect();

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertNull($payment->payment_link_expires_at);
        Http::assertSent(fn (Request $request): bool => ! isset($request->data()['Data']['ttl']));
    }

    public function test_different_users_cannot_exceed_one_use_limit(): void
    {
        $this->enableReservations();
        [$tariff, $promo] = $this->tariffAndPromo(usageLimit: 1);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->checkout($first, $tariff, $promo)->assertRedirect();

        $this->checkout($second, $tariff, $promo)
            ->assertSessionHasErrors('promo_code');

        $this->assertSame(1, Payment::where('promo_code_id', $promo->id)->count());
        $this->assertSame(1, $promo->activeReservationCount());
        Http::assertSentCount(1);
    }

    public function test_failed_reservation_releases_capacity(): void
    {
        $this->enableReservations();
        [$tariff, $promo] = $this->tariffAndPromo(usageLimit: 1);
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->checkout($first, $tariff, $promo)->assertRedirect();
        Payment::where('user_id', $first->id)->firstOrFail()->update(['status' => 'failed']);

        $this->checkout($second, $tariff, $promo)->assertRedirect();

        $this->assertSame(1, $promo->activeReservationCount());
        Http::assertSentCount(2);
    }

    public function test_expired_reservation_after_webhook_buffer_releases_capacity(): void
    {
        $this->enableReservations();
        [$tariff, $promo] = $this->tariffAndPromo(usageLimit: 1);
        $this->pendingReservation(
            User::factory()->create(),
            $tariff,
            $promo,
            now()->subMinutes(PromoCode::WEBHOOK_BUFFER_MINUTES + 1),
        );

        $buyer = User::factory()->create();
        $this->checkout($buyer, $tariff, $promo)->assertRedirect();

        $this->assertSame(1, $promo->activeReservationCount());
        Http::assertSentCount(1);
    }

    public function test_recently_expired_link_remains_reserved_during_webhook_buffer(): void
    {
        $this->enableReservations();
        [$tariff, $promo] = $this->tariffAndPromo(usageLimit: 1);
        $this->pendingReservation(
            User::factory()->create(),
            $tariff,
            $promo,
            now()->subMinutes(PromoCode::WEBHOOK_BUFFER_MINUTES - 1),
        );

        $buyer = User::factory()->create();
        $this->checkout($buyer, $tariff, $promo)->assertSessionHasErrors('promo_code');

        $this->assertSame(1, $promo->activeReservationCount());
        Http::assertNothingSent();
    }

    public function test_legacy_null_expiry_pending_row_remains_reserved(): void
    {
        $this->enableReservations();
        [$tariff, $promo] = $this->tariffAndPromo(usageLimit: 1);
        $this->pendingReservation(User::factory()->create(), $tariff, $promo, null);

        $buyer = User::factory()->create();
        $this->checkout($buyer, $tariff, $promo)->assertSessionHasErrors('promo_code');

        $this->assertSame(1, $promo->activeReservationCount());
        Http::assertNothingSent();
    }

    public function test_ttl_is_sent_and_stored_only_for_promo_backed_bank_links(): void
    {
        $this->enableReservations();
        [$tariff, $promo] = $this->tariffAndPromo();
        $promoBuyer = User::factory()->create();
        $regularBuyer = User::factory()->create();

        $before = now();
        $this->checkout($promoBuyer, $tariff, $promo)->assertRedirect();
        $this->actingAs($regularBuyer)
            ->post(route('payment.create'), ['tariff_id' => $tariff->id])
            ->assertRedirect();

        $promoPayment = Payment::where('user_id', $promoBuyer->id)->firstOrFail();
        $regularPayment = Payment::where('user_id', $regularBuyer->id)->firstOrFail();
        $this->assertNotNull($promoPayment->payment_link_expires_at);
        $this->assertTrue($promoPayment->payment_link_expires_at->between(
            $before->copy()->addMinutes(PromoCode::PAYMENT_LINK_TTL_MINUTES)->subSecond(),
            now()->addMinutes(PromoCode::PAYMENT_LINK_TTL_MINUTES)->addSecond(),
        ));
        $this->assertNull($regularPayment->payment_link_expires_at);

        $requests = Http::recorded()->map(fn (array $pair): array => $pair[0]->data());
        $this->assertSame(PromoCode::PAYMENT_LINK_TTL_MINUTES, $requests[0]['Data']['ttl']);
        $this->assertArrayNotHasKey('ttl', $requests[1]['Data']);
    }

    public function test_paid_reversal_and_legitimate_return_adjust_used_count_once(): void
    {
        $this->enableReservations();
        [$tariff, $promo] = $this->tariffAndPromo(usageLimit: 2);
        $user = User::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'promo_code_id' => $promo->id,
            'amount' => 4500,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $payment->update(['status' => 'paid']);
        $this->assertSame(1, $promo->fresh()->used_count);

        $payment->update(['status' => 'success']);
        $this->assertSame(1, $promo->fresh()->used_count, 'paid→success is not a second redemption.');

        $payment->update(['status' => 'canceled']);
        $this->assertSame(0, $promo->fresh()->used_count);

        $payment->update(['status' => 'paid']);
        $this->assertSame(1, $promo->fresh()->used_count);

        $payment->update(['status' => 'failed']);
        $this->assertSame(0, $promo->fresh()->used_count);
    }

    public function test_free_promo_becomes_paid_without_creating_ttl_link(): void
    {
        $this->enableReservations();
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['price' => 5000]);
        $promo = PromoCode::create([
            'code' => 'PROMOFREE',
            'type' => 'fixed',
            'value' => 5000,
            'usage_limit' => 1,
            'is_active' => true,
        ]);
        $user = User::factory()->create();

        $this->checkout($user, $tariff, $promo)
            ->assertRedirect(route('student.dashboard'));

        $payment = Payment::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('paid', $payment->status);
        $this->assertNull($payment->payment_link_expires_at);
        $this->assertSame(1, $promo->fresh()->used_count);
        Http::assertNothingSent();
    }

    private function enableReservations(): void
    {
        config()->set('features.checkout_promo_reservations', true);
    }

    private function tariffAndPromo(?int $usageLimit = null): array
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['price' => 5000]);
        $promo = PromoCode::create([
            'code' => 'PROMO10',
            'type' => 'percent',
            'value' => 10,
            'usage_limit' => $usageLimit,
            'is_active' => true,
        ]);

        return [$tariff, $promo];
    }

    private function checkout(User $user, Tariff $tariff, PromoCode $promo): TestResponse
    {
        return $this->actingAs($user)
            ->withSession(['promo_code' => $promo->code])
            ->post(route('payment.create'), ['tariff_id' => $tariff->id]);
    }

    private function pendingReservation(
        User $user,
        Tariff $tariff,
        PromoCode $promo,
        ?DateTimeInterface $expiresAt,
    ): Payment {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'promo_code_id' => $promo->id,
            'amount' => 4500,
            'tariff' => 'full',
            'status' => 'pending',
            'payment_link_expires_at' => $expiresAt,
        ]);
    }
}
