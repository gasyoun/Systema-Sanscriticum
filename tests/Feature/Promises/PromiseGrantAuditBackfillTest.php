<?php

declare(strict_types=1);

namespace Tests\Feature\Promises;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H7268 — append-only восстановление следа создания строки «доступ под обещание».
 *
 * Деньги и доступ не меняются; пишется только payment_audits.
 * Прогон: php artisan test --filter=PromiseGrantAuditBackfillTest
 */
class PromiseGrantAuditBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    /**
     * @return array{0: PaymentPromise, 1: Payment}
     */
    private function conditionalGrant(): array
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4800,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        app(ConditionalAccessGranter::class)
            ->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1]);

        return [$promise, Payment::query()->where('linked_promise_id', $promise->id)->sole()];
    }

    /** @test */
    public function dry_run_writes_nothing(): void
    {
        [, $payment] = $this->conditionalGrant();

        $this->assertSame(0, PaymentAudit::query()->where('payment_id', $payment->id)->count());

        $this->artisan('payments:backfill-promise-grant-audits')->assertSuccessful();

        $this->assertSame(0, PaymentAudit::query()->where('payment_id', $payment->id)->count());
    }

    /** @test */
    public function apply_writes_one_append_only_snapshot_and_never_touches_the_payment_row(): void
    {
        [, $payment] = $this->conditionalGrant();
        $before = $payment->fresh()->getAttributes();

        $this->artisan('payments:backfill-promise-grant-audits --apply')->assertSuccessful();

        $audits = PaymentAudit::query()->where('payment_id', $payment->id)->get();
        $this->assertCount(1, $audits);

        $audit = $audits->sole();
        $this->assertSame(PaymentAudit::ACTION_CREATED, $audit->action);
        $this->assertSame('promise_grant_#'.$payment->linked_promise_id, $audit->changes['transaction_id']);
        $this->assertTrue((bool) $audit->changes['is_conditional']);
        $this->assertArrayHasKey('audit_backfill', $audit->changes);

        // Строка платежа не изменилась ни в одном поле.
        $this->assertSame($before, $payment->fresh()->getAttributes());
    }

    /** @test */
    public function apply_is_idempotent(): void
    {
        [, $payment] = $this->conditionalGrant();

        $this->artisan('payments:backfill-promise-grant-audits --apply')->assertSuccessful();
        $this->artisan('payments:backfill-promise-grant-audits --apply')->assertSuccessful();

        $this->assertSame(1, PaymentAudit::query()->where('payment_id', $payment->id)->count());
    }

    /** @test */
    public function rows_that_break_the_contract_are_skipped_not_silently_repaired(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4800,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        $defective = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => false,
            'linked_promise_id' => $promise->id,
            'transaction_id' => 'promise_grant_#'.$promise->id,
            'first_paid_at' => now(),
        ]);

        $auditsBefore = PaymentAudit::query()->count();

        $this->artisan('payments:backfill-promise-grant-audits --apply')->assertSuccessful();

        // Обсервер уже записал created настоящего платежа; наша команда второго следа не пишет.
        $this->assertSame($auditsBefore, PaymentAudit::query()->count());
        $this->assertSame(1, PaymentAudit::query()->where('payment_id', $defective->id)->count());
        $this->assertFalse((bool) $defective->fresh()->is_conditional);
    }
}
