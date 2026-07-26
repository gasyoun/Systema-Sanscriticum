<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1645 — payments.first_paid_at closes the resurrection-guard audit blind
 * spot (docs/money-access-core-manual.md §9 C3). Covers exactly the four
 * cases the handoff calls for: stamp-once, create-as-paid, backfill dry-run
 * vs apply, and the guard detecting a paid-then-reversed payment whose paid
 * moment predates PaymentAuditObserver (08-06-2026) once backfilled.
 */
class FirstPaidAtTest extends TestCase
{
    use RefreshDatabase;

    private function makePendingPayment(): Payment
    {
        return Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function first_paid_at_is_stamped_once_and_never_moves_on_a_later_cycle(): void
    {
        $payment = $this->makePendingPayment();
        $this->assertNull($payment->first_paid_at);

        $payment->update(['status' => 'paid']);
        $firstStamp = $payment->fresh()->first_paid_at;
        $this->assertNotNull($firstStamp);

        // Реверс и повторная оплата — first_paid_at не двигается вперёд.
        $payment->update(['status' => 'failed']);
        $payment->update(['status' => 'paid']);

        $this->assertTrue($firstStamp->eq($payment->fresh()->first_paid_at));
    }

    /** @test */
    public function creating_a_payment_already_paid_stamps_first_paid_at_immediately(): void
    {
        $payment = Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertNotNull($payment->fresh()->first_paid_at);
    }

    /** @test */
    public function backfill_dry_run_reports_without_writing_then_apply_stamps_from_paid_audit(): void
    {
        $payment = $this->makePendingPayment();
        $payment->update(['status' => 'paid']);

        // Симулируем платёж, заведённый ДО H1645: колонка ещё не была
        // заполнена штатным write-path'ом при переходе в paid.
        $payment->updateQuietly(['first_paid_at' => null]);
        $this->assertNull($payment->fresh()->first_paid_at);
        $this->assertGreaterThan(0, $payment->audits()->count());

        $this->artisan('payments:backfill-first-paid-at')->assertSuccessful();
        $this->assertNull($payment->fresh()->first_paid_at, 'dry-run (без --apply) не должен писать в БД');

        $this->artisan('payments:backfill-first-paid-at', ['--apply' => true])->assertSuccessful();
        $this->assertNotNull($payment->fresh()->first_paid_at);

        // Идемпотентность: повторный --apply над уже забэкфилленным платежом — no-op.
        $stamped = $payment->fresh()->first_paid_at;
        $this->artisan('payments:backfill-first-paid-at', ['--apply' => true])->assertSuccessful();
        $this->assertTrue($stamped->eq($payment->fresh()->first_paid_at));
    }

    /** @test */
    public function guard_detects_paid_then_reversed_payment_predating_the_observer_once_backfilled(): void
    {
        config(['features.tochka_webhook_guard' => true]);

        // Платёж оплачен ДО появления PaymentAuditObserver (08-06-2026):
        // withoutEvents -> ни одной audit-строки, first_paid_at ещё не
        // существовал -> NULL.
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => User::factory()->create()->id,
            'course_id' => Course::factory()->create()->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
            'created_at' => '2026-05-01 10:00:00',
        ]));
        $this->assertNull($payment->fresh()->first_paid_at);
        $this->assertSame(0, $payment->audits()->count());

        // Возврат ПОСЛЕ появления обсёрвера — обычный (observed) update.
        // Единственная audit-строка: updated, status: [paid, failed].
        $payment->update(['status' => 'failed']);
        $this->assertSame(1, $payment->fresh()->audits()->count());

        // Step 0 hardening уже ловит воскрешение по старому значению этого
        // дифа — даже без бэкофилла.
        $this->assertTrue($payment->fresh()->hasPriorPaidTransition());

        $this->artisan('payments:backfill-first-paid-at', ['--apply' => true])->assertSuccessful();
        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->first_paid_at);

        // После бэкофилла гвард детектирует воскрешение через fast-path
        // first_paid_at — независимо от того, сохранится ли аудит-след.
        $this->assertTrue($fresh->hasPriorPaidTransition());
    }
}
