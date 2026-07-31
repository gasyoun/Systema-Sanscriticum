<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPaymentToSheetJob;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * H1960: payments sheet webhook sends X-Webhook-Secret when configured.
 */
class SendPaymentToSheetJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** @test */
    public function posts_with_webhook_secret_header_when_configured(): void
    {
        config([
            'services.n8n.payments_webhook' => 'https://n8n.example.test/webhook/payments',
            'services.n8n.payments_webhook_secret' => 'test-payments-secret',
        ]);

        Http::fake([
            'n8n.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $paymentId = $payment->id;
        (new SendPaymentToSheetJob($paymentId, 'create'))->handle();

        Http::assertSent(function ($request) use ($paymentId) {
            return $request->url() === 'https://n8n.example.test/webhook/payments'
                && $request->hasHeader('X-Webhook-Secret', 'test-payments-secret')
                && ($request['id'] ?? null) === $paymentId;
        });
    }

    /** @test */
    public function omits_secret_header_when_secret_empty(): void
    {
        config([
            'services.n8n.payments_webhook' => 'https://n8n.example.test/webhook/payments',
            'services.n8n.payments_webhook_secret' => null,
        ]);

        Http::fake([
            'n8n.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        (new SendPaymentToSheetJob($payment->id, 'create'))->handle();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://n8n.example.test/webhook/payments'
                && ! $request->hasHeader('X-Webhook-Secret');
        });
    }

    /** @test */
    public function skips_when_webhook_url_empty(): void
    {
        config([
            'services.n8n.payments_webhook' => null,
            'services.n8n.payments_webhook_secret' => 'unused',
        ]);

        Http::fake();

        $user = User::factory()->create();
        $course = Course::factory()->create();
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        (new SendPaymentToSheetJob($payment->id, 'create'))->handle();

        Http::assertNothingSent();
    }
}
