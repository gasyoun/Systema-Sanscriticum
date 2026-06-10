<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Сверка conditional-доступа «под обещание» с реальной оплатой того же тарифа:
 * перекрытые conditional-платежи снимаются, обещание закрывается, когда по нему
 * не осталось открытых conditional-доступов.
 */
class ConditionalGrantReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
    }

    private function promiseFor(User $user, Course $course): PaymentPromise
    {
        return PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);
    }

    /** @test */
    public function paying_a_conditionally_open_block_removes_grant_and_fulfils_promise(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($user, $course);

        app(ConditionalAccessGranter::class)->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1]);

        $this->assertDatabaseHas('payments', [
            'course_id' => $course->id,
            'tariff' => 'block_1',
            'is_conditional' => true,
        ]);

        // Реальная оплата того же блока (как из витрины/вебхука).
        $real = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        // Conditional-платёж снят — остаётся только реальный.
        $this->assertDatabaseMissing('payments', [
            'course_id' => $course->id,
            'tariff' => 'block_1',
            'is_conditional' => true,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $real->id,
            'is_conditional' => false,
        ]);

        // Обещание закрыто оплатой.
        $promise->refresh();
        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $promise->status);
        $this->assertSame($real->id, $promise->fulfilled_payment_id);
    }

    /** @test */
    public function partial_payment_of_multi_block_promise_keeps_promise_active(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($user, $course);

        app(ConditionalAccessGranter::class)->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1, 2]);

        // Оплачен только блок 1.
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 2000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        // block_1 снят, block_2 ещё открыт под обещание.
        $this->assertDatabaseMissing('payments', [
            'tariff' => 'block_1',
            'is_conditional' => true,
        ]);
        $this->assertDatabaseHas('payments', [
            'tariff' => 'block_2',
            'is_conditional' => true,
        ]);

        // Обещание не закрыто — по нему ещё висит открытый блок.
        $promise->refresh();
        $this->assertSame(PaymentPromise::STATUS_ACTIVE, $promise->status);
    }

    /** @test */
    public function full_payment_supersedes_all_conditional_blocks(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = $this->promiseFor($user, $course);

        app(ConditionalAccessGranter::class)->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1, 2]);

        $real = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 8000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        // Полная оплата перекрывает все conditional-доступы курса.
        $this->assertSame(0, Payment::query()->conditional()->where('course_id', $course->id)->count());

        $promise->refresh();
        $this->assertSame(PaymentPromise::STATUS_FULFILLED, $promise->status);
        $this->assertSame($real->id, $promise->fulfilled_payment_id);
    }
}
