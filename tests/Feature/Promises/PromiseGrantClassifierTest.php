<?php

declare(strict_types=1);

namespace Tests\Feature\Promises;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use App\Services\Promises\PromiseGrantClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H7268 — контракт строки «доступ под обещание» и read-only классификатор.
 *
 * Прогон: php artisan test --filter=PromiseGrantClassifierTest
 */
class PromiseGrantClassifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    private function promiseFor(User $user, Course $course, string $status = PaymentPromise::STATUS_ACTIVE): PaymentPromise
    {
        return PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4800,
            'status' => $status,
        ]);
    }

    /** @test */
    public function promise_grant_row_follows_the_contract_and_is_excluded_from_revenue(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($user, $course);

        app(ConditionalAccessGranter::class)
            ->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1]);

        $payment = Payment::query()->where('linked_promise_id', $promise->id)->sole();

        $this->assertSame(0.0, (float) $payment->amount);
        $this->assertTrue((bool) $payment->is_conditional);
        $this->assertContains($payment->status, Payment::PAID_STATUSES);
        $this->assertSame('promise_grant_#'.$promise->id, $payment->transaction_id);
        $this->assertSame($promise->user_id, $payment->user_id);
        $this->assertSame($promise->course_id, $payment->course_id);

        // Zero-amount строка обещания — не выручка ни в одном агрегате.
        $this->assertFalse(Payment::query()->real()->whereKey($payment->id)->exists());

        $result = app(PromiseGrantClassifier::class)->classify($payment);

        $this->assertSame(PromiseGrantClassifier::VERDICT_LEGIT_SILENT, $result['verdict']);
        $this->assertSame('audit_backfill', $result['repair']);
        $this->assertSame(
            ['silent_creation_no_audit'],
            array_column($result['findings'], 'code'),
            'У контрактной строки без аудита единственная находка — документированный пробел следа.',
        );
    }

    /** @test */
    public function zero_amount_paid_row_without_conditional_flag_is_a_defect(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($user, $course);

        $payment = Payment::create([
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

        $result = app(PromiseGrantClassifier::class)->classify($payment);

        $this->assertSame(PromiseGrantClassifier::VERDICT_DEFECT, $result['verdict']);
        $this->assertSame('human', $result['repair']);

        $codes = array_column($result['findings'], 'code');
        $this->assertContains('conditional_flag_missing', $codes);
        $this->assertContains('revenue_scope_leak', $codes);
    }

    /** @test */
    public function transaction_id_and_linked_promise_must_name_the_same_promise(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $first = $this->promiseFor($user, $course);
        $second = $this->promiseFor($user, $course);

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => true,
            'linked_promise_id' => $first->id,
            'transaction_id' => 'promise_grant_#'.$second->id,
            'first_paid_at' => now(),
        ]);

        $result = app(PromiseGrantClassifier::class)->classify($payment);

        $this->assertSame(PromiseGrantClassifier::VERDICT_DEFECT, $result['verdict']);
        $this->assertContains('promise_link_mismatch', array_column($result['findings'], 'code'));
    }

    /** @test */
    public function promise_of_another_student_is_a_defect(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($alice, $course);

        $payment = Payment::create([
            'user_id' => $bob->id,
            'course_id' => $course->id,
            'amount' => 0,
            'tariff' => 'block_1',
            'status' => 'paid',
            'is_conditional' => true,
            'linked_promise_id' => $promise->id,
            'transaction_id' => 'promise_grant_#'.$promise->id,
            'first_paid_at' => now(),
        ]);

        $result = app(PromiseGrantClassifier::class)->classify($payment);

        $this->assertSame(PromiseGrantClassifier::VERDICT_DEFECT, $result['verdict']);
        $this->assertContains('promise_student_mismatch', array_column($result['findings'], 'code'));
    }

    /** @test */
    public function classifier_command_is_read_only_and_passes_on_contract_rows(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($user, $course);

        app(ConditionalAccessGranter::class)
            ->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1]);

        $payments = DB::table('payments')->count();
        $audits = DB::table('payment_audits')->count();
        $groups = DB::table('group_user')->count();
        $courses = DB::table('course_user')->count();

        $this->artisan('payments:classify-promise-grants')->assertSuccessful();

        $this->assertSame($payments, DB::table('payments')->count());
        $this->assertSame($audits, DB::table('payment_audits')->count());
        $this->assertSame($groups, DB::table('group_user')->count());
        $this->assertSame($courses, DB::table('course_user')->count());
    }

    /** @test */
    public function classifier_command_fails_when_a_promise_grant_row_breaks_the_contract(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($user, $course);

        Payment::create([
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

        $this->artisan('payments:classify-promise-grants')->assertFailed();
    }
}
