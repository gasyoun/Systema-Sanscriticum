<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\ConditionalAccessGranter;
use App\Services\InstallmentPlanCreator;
use App\Services\PromiseFulfillment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CuratorNotifierTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        Http::fake();
        config([
            'services.telegram.curators_chat_id' => '-1001234567890',
            'services.telegram.bot_token' => 'test-token',
        ]);
    }

    /** @test */
    public function real_payment_notifies_curators(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        Queue::assertPushed(SendTelegramChatMessageJob::class, function (SendTelegramChatMessageJob $job) use ($course) {
            return $job->chatId === '-1001234567890'
                && str_contains($job->text, 'Оплата')
                && str_contains($job->text, $course->title)
                && str_contains($job->text, '5 000 ₽');
        });
    }

    /** @test */
    public function deposit_notifies_curators_as_booking(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job) => str_contains($job->text, 'Бронь'));
    }

    /** @test */
    public function standalone_promise_creation_notifies_curators(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 3000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job) => str_contains($job->text, 'Новое обещание'));
    }

    /** @test */
    public function installment_plan_notifies_curators_once(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        app(InstallmentPlanCreator::class)->create($user, $course, [
            ['promised_at' => now()->addWeek()->toDateString(), 'amount' => 2000],
            ['promised_at' => now()->addMonth()->toDateString(), 'amount' => 2000],
            ['promised_at' => now()->addMonths(2)->toDateString(), 'amount' => 2000],
        ], 'Тестовый план');

        // Ровно одно сообщение на весь план — не по одному на каждую строку графика.
        Queue::assertPushed(SendTelegramChatMessageJob::class, 1);
        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job) => str_contains($job->text, 'Создана рассрочка')
            && str_contains($job->text, '6 000 ₽'));
    }

    /** @test */
    public function conditional_access_grant_notifies_curators(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        app(ConditionalAccessGranter::class)->grantForPromise($promise, ConditionalAccessGranter::MODE_BLOCKS, [1, 2]);

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job) => str_contains($job->text, 'Открыт доступ под обещание'));
    }

    /** @test */
    public function silent_fulfilment_notifies_curators(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $promise = PaymentPromise::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'promised_at' => now()->addWeek()->toDateString(),
            'amount' => 4000,
            'status' => PaymentPromise::STATUS_ACTIVE,
        ]);

        app(PromiseFulfillment::class)->fulfil($promise, [
            'amount' => 4000,
            'start_block' => 1,
            'end_block' => 2,
        ], silent: true);

        Queue::assertPushed(SendTelegramChatMessageJob::class, fn (SendTelegramChatMessageJob $job) => str_contains($job->text, 'Обещание закрыто оплатой'));
    }

    /** @test */
    public function nothing_sent_when_curators_chat_not_configured(): void
    {
        config(['services.telegram.curators_chat_id' => null]);

        $user = User::factory()->create();
        $course = Course::factory()->create();

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 5000,
            'tariff' => 'full',
            'status' => 'paid',
        ]);

        Queue::assertNotPushed(SendTelegramChatMessageJob::class);
    }
}
