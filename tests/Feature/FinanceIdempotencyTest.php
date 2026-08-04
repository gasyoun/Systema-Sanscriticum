<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\PromiseFulfillment;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** @test */
    public function a_sequential_retry_with_a_stale_promise_cannot_create_a_second_payment(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = PaymentPromise::withoutEvents(fn () => PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]));
        $staleRetry = PaymentPromise::findOrFail($promise->id);

        $firstPayment = app(PromiseFulfillment::class)->fulfil($promise, [
            'amount' => 4000,
            'start_block' => 1,
            'end_block' => 1,
        ], silent: true);

        try {
            app(PromiseFulfillment::class)->fulfil($staleRetry, [
                'amount' => 4000,
                'start_block' => 1,
                'end_block' => 1,
            ], silent: true);
            $this->fail('A fulfilled promise must reject a retry.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('promise', $exception->errors());
        }

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame($firstPayment->id, $promise->fresh()->fulfilled_payment_id);
    }

    /** @test */
    public function fulfilled_payment_link_is_unique_while_multiple_null_links_are_allowed(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promises = collect([1, 2])->map(fn (int $week) => PaymentPromise::withoutEvents(
            fn () => PaymentPromise::create([
                'user_id' => $user->id,
                'course_id' => $course->id,
                'promised_at' => now()->addWeeks($week)->toDateString(),
                'amount' => 2000,
                'status' => PaymentPromise::STATUS_ACTIVE,
            ])
        ));
        $payment = Payment::withoutEvents(fn () => Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 2000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]));

        $promises[0]->update(['fulfilled_payment_id' => $payment->id]);

        try {
            $promises[1]->update(['fulfilled_payment_id' => $payment->id]);
            $this->fail('The same payment must not link to two promises.');
        } catch (QueryException) {
            $this->assertNull($promises[1]->fresh()->fulfilled_payment_id);
        }
    }
}
