<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PromoCode;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StaleCheckoutExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reaper_is_on_by_default(): void
    {
        // MG 01-08-2026: permanent dark was false economy — default ON.
        $this->assertTrue(config('features.checkout_stale_order_expiry'));
    }

    public function test_flag_off_refuses_to_apply_but_dry_run_still_reports(): void
    {
        config()->set('features.checkout_stale_order_expiry', false);

        [$course, $tariff] = $this->courseAndTariff();
        $payment = $this->pendingPayment($course, $tariff, ['created_at' => now()->subDays(40)]);

        // Soft no-op (exit 0): schedule noise when flag is off must not be ERROR.
        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();
        $this->assertSame('pending', $payment->fresh()->status);

        $this->artisan('payments:expire-stale-checkouts')
            ->assertSuccessful()
            ->expectsOutputToContain('would fail 1');
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_expired_promo_reservation_releases_the_slot(): void
    {
        $this->enableReaper();
        config()->set('features.checkout_promo_reservations', true);
        [$course, $tariff] = $this->courseAndTariff();
        $promo = PromoCode::create([
            'code' => 'STALEPROMO',
            'type' => 'percent',
            'value' => 10,
            'usage_limit' => 1,
            'is_active' => true,
        ]);
        $payment = $this->pendingPayment($course, $tariff, [
            'promo_code_id' => $promo->id,
            'created_at' => now()->subMinutes(5),
            'payment_link_expires_at' => now()->subMinutes(PromoCode::WEBHOOK_BUFFER_MINUTES + 1),
        ]);

        // Past its own TTL + webhook buffer, this row already doesn't count
        // toward capacity (PromoCode::activeReservationCount) — that part is
        // automatic by design (research finding: promo slots free themselves
        // purely by time passing). What the reaper adds is cleaning up the
        // debris row itself, which AuditCheckoutIntegrity flags as needing
        // exactly this kind of resolution.
        $this->assertSame(0, $promo->activeReservationCount());
        $this->assertSame('pending', $payment->fresh()->status);

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame(0, $promo->activeReservationCount());
        $this->assertSame(0, $promo->fresh()->used_count);
    }

    public function test_live_promo_reservation_within_ttl_and_buffer_is_left_alone(): void
    {
        $this->enableReaper();
        config()->set('features.checkout_promo_reservations', true);
        [$course, $tariff] = $this->courseAndTariff();
        $promo = PromoCode::create([
            'code' => 'LIVEPROMO',
            'type' => 'percent',
            'value' => 10,
            'usage_limit' => 1,
            'is_active' => true,
        ]);
        $payment = $this->pendingPayment($course, $tariff, [
            'promo_code_id' => $promo->id,
            'created_at' => now()->subMinutes(5),
            'payment_link_expires_at' => now()->subMinutes(PromoCode::WEBHOOK_BUFFER_MINUTES - 1),
        ]);

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame(1, $promo->activeReservationCount());
    }

    public function test_prana_and_referral_credit_are_returned_exactly_once_across_two_runs(): void
    {
        $this->enableReaper();
        [$course, $tariff] = $this->courseAndTariff();
        $user = User::factory()->create(['prana_balance' => 0, 'referral_credit' => 0]);
        $payment = $this->pendingPayment($course, $tariff, [
            'user_id' => $user->id,
            'prana_spent' => 40,
            'referral_credit_applied' => 15,
            'created_at' => now()->subDays(40),
        ]);

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();
        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $payment->refresh();
        $this->assertSame('failed', $payment->status);
        $this->assertSame(0.0, (float) $payment->referral_credit_applied);
        $this->assertSame(40, $user->fresh()->prana_balance);
        $this->assertSame(15.0, (float) $user->fresh()->referral_credit);
    }

    public function test_deposit_and_trial_pending_rows_are_never_touched(): void
    {
        $this->enableReaper();
        [$course, $tariff] = $this->courseAndTariff();
        $deposit = $this->pendingPayment($course, $tariff, ['tariff' => 'deposit', 'created_at' => now()->subDays(40)]);
        $trial = $this->pendingPayment($course, $tariff, ['tariff' => 'trial', 'created_at' => now()->subDays(40)]);

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $this->assertSame('pending', $deposit->fresh()->status);
        $this->assertSame('pending', $trial->fresh()->status);
    }

    public function test_paypal_pending_rows_are_never_touched(): void
    {
        $this->enableReaper();
        [$course, $tariff] = $this->courseAndTariff();
        $paypal = $this->pendingPayment($course, $tariff, [
            'provider' => Payment::PROVIDER_PAYPAL,
            'created_at' => now()->subDays(40),
        ]);

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $this->assertSame('pending', $paypal->fresh()->status);
    }

    public function test_conditional_pending_rows_are_never_touched(): void
    {
        $this->enableReaper();
        [$course, $tariff] = $this->courseAndTariff();
        $conditional = $this->pendingPayment($course, $tariff, [
            'is_conditional' => true,
            'created_at' => now()->subDays(40),
        ]);

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $this->assertSame('pending', $conditional->fresh()->status);
    }

    public function test_a_still_live_legacy_pending_row_within_the_grace_window_is_left_alone(): void
    {
        $this->enableReaper();
        [$course, $tariff] = $this->courseAndTariff();
        $payment = $this->pendingPayment($course, $tariff, ['created_at' => now()->subDays(5)]);

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_reaping_lifts_the_second_pending_order_block_for_the_same_course(): void
    {
        $this->enableReaper();
        [$course, $tariff] = $this->courseAndTariff();
        $user = User::factory()->create();

        // Paid, unconsumed deposit for this course + a stale non-deposit pending
        // order is exactly the combination PaymentController::create() blocks
        // ("У вас уже есть незавершённый заказ по этому курсу...").
        $this->pendingPayment($course, $tariff, [
            'user_id' => $user->id,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
        $this->pendingPayment($course, $tariff, [
            'user_id' => $user->id,
            'created_at' => now()->subDays(40),
        ]);

        $this->assertTrue(
            Payment::query()->hasOtherPendingOrderForCourse($user->id, $course->id)->exists()
        );

        $this->artisan('payments:expire-stale-checkouts --apply')->assertSuccessful();

        $this->assertFalse(
            Payment::query()->hasOtherPendingOrderForCourse($user->id, $course->id)->exists()
        );
    }

    private function enableReaper(): void
    {
        config()->set('features.checkout_stale_order_expiry', true);
    }

    private function courseAndTariff(): array
    {
        $course = Course::factory()->create();
        $tariff = Tariff::factory()->for($course)->create(['price' => 5000]);

        return [$course, $tariff];
    }

    private function pendingPayment(Course $course, Tariff $tariff, array $overrides = []): Payment
    {
        $userId = $overrides['user_id'] ?? User::factory()->create()->id;
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['user_id'], $overrides['created_at']);

        $payment = Payment::withoutEvents(fn () => Payment::create(array_merge([
            'user_id' => $userId,
            'course_id' => $course->id,
            'amount' => $tariff->price,
            'tariff' => 'full',
            'status' => 'pending',
        ], $overrides)));

        // created_at is not mass-assignable; backdate it directly so the
        // reaper's age-based threshold sees a genuinely stale checkout.
        if ($createdAt !== null) {
            DB::table('payments')->where('id', $payment->id)->update(['created_at' => $createdAt]);
            $payment->refresh();
        }

        return $payment;
    }
}
