<?php

declare(strict_types=1);

namespace Tests\Feature\Deposit;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DepositReversalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Mail::fake();
    }

    public function test_deposit_reversal_is_dark_by_default(): void
    {
        $this->assertFalse(config('features.checkout_deposit_reversal'));
    }

    public function test_flag_off_preserves_existing_non_restoring_behavior(): void
    {
        [$user, $course] = $this->buyerAndCourse();
        $deposit = $this->deposit($user, $course, 1000);
        $purchase = $this->paidPurchase($user, $course, 1000);

        $purchase->update(['status' => 'failed']);

        $this->assertDepositState($deposit, 1000, true);
        $this->assertSame(0.0, $this->availableDeposit($user, $course));
    }

    public function test_full_consumption_is_restored_and_purchase_marker_is_preserved(): void
    {
        config()->set('features.checkout_deposit_reversal', true);

        [$user, $course] = $this->buyerAndCourse();
        $deposit = $this->deposit($user, $course, 2000);
        $purchase = $this->paidPurchase($user, $course, 2000);

        $this->assertDepositState($deposit, 2000, true);

        $purchase->update(['status' => 'failed']);

        $this->assertDepositState($deposit, null, false);
        $this->assertSame(2000.0, (float) $purchase->fresh()->deposit_credit_applied);
        $this->assertSame(2000.0, $this->availableDeposit($user, $course));
    }

    public function test_partial_consumption_is_fully_restored(): void
    {
        config()->set('features.checkout_deposit_reversal', true);

        [$user, $course] = $this->buyerAndCourse();
        $deposit = $this->deposit($user, $course, 5000);
        $purchase = $this->paidPurchase($user, $course, 2000);

        $this->assertDepositState($deposit, 2000, false);

        $purchase->update(['status' => 'canceled']);

        $this->assertDepositState($deposit, null, false);
        $this->assertSame(5000.0, $this->availableDeposit($user, $course));
    }

    public function test_multi_deposit_restoration_unwinds_newest_first(): void
    {
        config()->set('features.checkout_deposit_reversal', true);

        [$user, $course] = $this->buyerAndCourse();
        $older = $this->deposit($user, $course, 1000);
        $older->updateQuietly(['created_at' => now()->subDay()]);
        $newer = $this->deposit($user, $course, 1000);

        // Совокупное consumption может включать другой более поздний заказ.
        // Откат текущих 1500 обязан снять сначала newest 1000, затем older 500.
        $older->updateQuietly(['consumed_amount' => 1000, 'deposit_consumed_at' => now()]);
        $newer->updateQuietly(['consumed_amount' => 1000, 'deposit_consumed_at' => now()]);
        $purchase = $this->paidPurchaseWithoutConsumption($user, $course, 1500);

        $purchase->update(['status' => 'failed']);

        $this->assertDepositState($newer, null, false);
        $this->assertDepositState($older, 500, false);
        $this->assertSame(1500.0, $this->availableDeposit($user, $course));
    }

    public function test_repeated_failed_or_canceled_updates_are_idempotent(): void
    {
        config()->set('features.checkout_deposit_reversal', true);

        [$user, $course] = $this->buyerAndCourse();
        $deposit = $this->deposit($user, $course, 1200);
        $purchase = $this->paidPurchase($user, $course, 1200);

        $purchase->update(['status' => 'failed']);
        $purchase->update(['status' => 'canceled']);
        $purchase->update(['status' => 'canceled']);

        $this->assertDepositState($deposit, null, false);
        $this->assertSame(1200.0, $this->availableDeposit($user, $course));
    }

    public function test_canceled_paid_canceled_round_trip_preserves_aggregate_balance(): void
    {
        config()->set('features.checkout_deposit_reversal', true);

        [$user, $course] = $this->buyerAndCourse();
        $deposit = $this->deposit($user, $course, 2000);
        $purchase = $this->paidPurchase($user, $course, 1500);

        $this->assertSame(500.0, $this->availableDeposit($user, $course));

        $purchase->update(['status' => 'canceled']);
        $this->assertSame(2000.0, $this->availableDeposit($user, $course));

        $purchase->update(['status' => 'paid']);
        $this->assertSame(500.0, $this->availableDeposit($user, $course));

        $purchase->update(['status' => 'canceled']);
        $this->assertDepositState($deposit, null, false);
        $this->assertSame(2000.0, $this->availableDeposit($user, $course));
        $this->assertSame(1500.0, (float) $purchase->fresh()->deposit_credit_applied);
    }

    public function test_legacy_null_purchase_marker_is_not_guessed(): void
    {
        config()->set('features.checkout_deposit_reversal', true);

        [$user, $course] = $this->buyerAndCourse();
        $deposit = $this->deposit($user, $course, 1000);
        $deposit->updateQuietly(['consumed_amount' => 1000, 'deposit_consumed_at' => now()]);
        $purchase = $this->paidPurchaseWithoutConsumption($user, $course, null);

        $purchase->update(['status' => 'failed']);

        $this->assertDepositState($deposit, 1000, true);
        $this->assertSame(0.0, $this->availableDeposit($user, $course));
    }

    public function test_restoration_shortfall_is_logged_without_inventing_credit(): void
    {
        config()->set('features.checkout_deposit_reversal', true);
        Log::spy();

        [$user, $course] = $this->buyerAndCourse();
        $deposit = $this->deposit($user, $course, 500);
        $deposit->updateQuietly(['consumed_amount' => 500, 'deposit_consumed_at' => now()]);
        $purchase = $this->paidPurchaseWithoutConsumption($user, $course, 1000);

        $purchase->update(['status' => 'failed']);

        $this->assertDepositState($deposit, null, false);
        $this->assertSame(500.0, $this->availableDeposit($user, $course));
        Log::shouldHaveReceived('warning')->once()->with(
            'Payment deposit restoration shortfall',
            \Mockery::on(fn (array $context): bool => $context['payment_id'] === $purchase->id
                && $context['deposit_credit_applied'] === 1000.0
                && $context['restored_amount'] === 500.0
                && $context['shortfall'] === 500.0)
        );
    }

    private function buyerAndCourse(): array
    {
        return [User::factory()->create(), Course::factory()->create()];
    }

    private function deposit(User $user, Course $course, float $amount): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);
    }

    private function paidPurchase(User $user, Course $course, float $applied): Payment
    {
        $purchase = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 3000,
            'tariff' => 'full',
            'status' => 'pending',
            'deposit_credit_applied' => $applied,
        ]);
        $purchase->update(['status' => 'paid']);

        return $purchase->fresh();
    }

    private function paidPurchaseWithoutConsumption(User $user, Course $course, ?float $applied): Payment
    {
        $purchase = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 3000,
            'tariff' => 'full',
            'status' => 'pending',
            'deposit_credit_applied' => $applied,
        ]);
        $purchase->updateQuietly(['status' => 'paid']);

        return $purchase->fresh();
    }

    private function assertDepositState(Payment $deposit, ?float $consumed, bool $stamped): void
    {
        $deposit->refresh();

        if ($consumed === null) {
            $this->assertNull($deposit->consumed_amount);
        } else {
            $this->assertSame($consumed, (float) $deposit->consumed_amount);
        }

        $stamped
            ? $this->assertNotNull($deposit->deposit_consumed_at)
            : $this->assertNull($deposit->deposit_consumed_at);
    }

    private function availableDeposit(User $user, Course $course): float
    {
        return (float) Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->whereIn('tariff', ['deposit', 'trial'])
            ->paid()
            ->get()
            ->sum(fn (Payment $deposit): float => max(
                0,
                (float) $deposit->amount - (float) ($deposit->consumed_amount ?? 0)
            ));
    }
}
