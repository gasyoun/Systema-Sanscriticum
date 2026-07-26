<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use App\Services\PromiseFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1645 — payments.first_paid_at: резервная опора resurrection-guard'а
 * (Payment::hasPriorPaidTransition()), не зависящая от payment_audits.
 * Покрывает: стамп-once на обычном пути (create-as-paid / pending->paid),
 * стамп на withoutEvents create-as-paid путях (silent PromiseFulfillment,
 * ConditionalAccessGranter), бэкфилл-команду (dry-run/apply/residual) и
 * усиленный (old+new) аудит-обход guard'а.
 */
class FirstPaidAtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    private function makeUserCourse(): array
    {
        return [User::factory()->create(), Course::factory()->create()];
    }

    // ================= Write path: stamp-once =================

    /** @test */
    public function create_as_paid_stamps_first_paid_at(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $this->assertNotNull($payment->fresh()->first_paid_at);
    }

    /** @test */
    public function transition_into_paid_stamps_first_paid_at(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'pending',
        ]);

        $this->assertNull($payment->fresh()->first_paid_at);

        $payment->update(['status' => 'paid']);

        $this->assertNotNull($payment->fresh()->first_paid_at);
    }

    /** @test */
    public function first_paid_at_is_never_overwritten_by_a_later_relapse(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        $original = $payment->fresh()->first_paid_at;
        $this->assertNotNull($original);

        $this->travel(1)->hour();

        // Отменяем и снова оплачиваем — второй вход в paid не должен сдвинуть штамп.
        $payment->update(['status' => 'failed']);
        $payment->update(['status' => 'paid']);

        $this->assertTrue($original->equalTo($payment->fresh()->first_paid_at));
    }

    /** @test */
    public function silent_promise_fulfillment_create_as_paid_stamps_first_paid_at(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $promise = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $payment = app(PromiseFulfillment::class)->fulfil($promise, [
            'amount' => 4000,
            'start_block' => 1,
            'end_block' => 2,
        ], silent: true);

        $this->assertNotNull($payment->fresh()->first_paid_at);
    }

    /** @test */
    public function conditional_access_grant_create_as_paid_stamps_first_paid_at(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $promise = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $ids = app(ConditionalAccessGranter::class)->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1]);

        $payment = Payment::find($ids[0]);
        $this->assertNotNull($payment->first_paid_at);
    }

    // ================= Guard: enhanced (old+new) audit walk =================

    /**
     * Платёж создан ДО эры аудита (withoutEvents-эквивалент: чистим и
     * created-снимок, и first_paid_at, чтобы смоделировать "старые" данные до
     * этого фикса), затем отменён ПОСЛЕ подключения PaymentAuditObserver —
     * аудит фиксирует только apdate-дифф paid->failed (новое значение —
     * failed). До фикса 2a guard эту запись не видел (проверял только
     * $status[1]); теперь старое значение диффа доказывает прежний paid.
     *
     * @test
     */
    public function guard_detects_reversal_via_old_value_of_audit_diff_without_backfill(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Симулируем «старый» до-фикса платёж: ни первого снимка, ни first_paid_at.
        PaymentAudit::where('payment_id', $payment->id)->delete();
        $payment->forceFill(['first_paid_at' => null])->saveQuietly();

        $payment->update(['status' => 'failed']);

        $this->assertTrue($payment->fresh()->hasPriorPaidTransition());
    }

    /** @test */
    public function guard_still_blind_to_pre_observer_reversal_with_zero_audit_trace(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Ни одной строки аудита нигде (ни created, ни update-дифф) — как если
        // бы платёж целиком прожил цикл paid->failed до PaymentAuditObserver.
        PaymentAudit::where('payment_id', $payment->id)->delete();
        $payment->forceFill(['first_paid_at' => null, 'status' => 'failed'])->saveQuietly();
        PaymentAudit::where('payment_id', $payment->id)->delete();

        $this->assertFalse($payment->fresh()->hasPriorPaidTransition());
    }

    // ================= Backfill command =================

    /** @test */
    public function backfill_dry_run_reports_but_does_not_write(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        $payment->forceFill(['first_paid_at' => null])->saveQuietly();

        $this->artisan('payments:backfill-first-paid-at')
            ->expectsOutputToContain('Платежей к бэкфиллу: 1')
            ->assertSuccessful();

        $this->assertNull($payment->fresh()->first_paid_at);
    }

    /** @test */
    public function backfill_apply_sets_first_paid_at_from_earliest_audit_evidence(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        $payment->forceFill(['first_paid_at' => null])->saveQuietly();

        $payment->update(['status' => 'failed']);

        $this->artisan('payments:backfill-first-paid-at --apply')->assertSuccessful();

        $this->assertNotNull($payment->fresh()->first_paid_at);
        $this->assertTrue($payment->fresh()->hasPriorPaidTransition());
    }

    /** @test */
    public function backfill_apply_falls_back_to_created_at_when_currently_paid_with_no_audit_trail(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        PaymentAudit::where('payment_id', $payment->id)->delete();
        $payment->forceFill(['first_paid_at' => null])->saveQuietly();

        $this->artisan('payments:backfill-first-paid-at --apply')->assertSuccessful();

        $fresh = $payment->fresh();
        $this->assertNotNull($fresh->first_paid_at);
        $this->assertTrue($fresh->created_at->equalTo($fresh->first_paid_at));
    }

    /** @test */
    public function backfill_leaves_residual_null_and_reports_its_count(): void
    {
        [$user, $course] = $this->makeUserCourse();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'full',
            'status' => 'paid',
        ]);
        // Ни следа: не оплачен сейчас, ни одной строки аудита с paid-эвиденцией.
        PaymentAudit::where('payment_id', $payment->id)->delete();
        $payment->forceFill(['first_paid_at' => null, 'status' => 'failed'])->saveQuietly();
        PaymentAudit::where('payment_id', $payment->id)->delete();

        $this->artisan('payments:backfill-first-paid-at --apply')
            ->expectsOutputToContain('Остаток без следа')
            ->assertSuccessful();

        $this->assertNull($payment->fresh()->first_paid_at);
        $this->assertFalse($payment->fresh()->hasPriorPaidTransition());
    }
}
