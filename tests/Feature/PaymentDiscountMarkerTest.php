<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendPaymentToSheetJob;
use App\Models\Course;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentDiscountMarkerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
    }

    /** @test */
    public function has_discount_reflects_stored_percent(): void
    {
        $discounted = new Payment(['discount_percent' => 20]);
        $this->assertTrue($discounted->hasDiscount());

        $plain = new Payment(['discount_percent' => null]);
        $this->assertFalse($plain->hasDiscount());

        $zero = new Payment(['discount_percent' => 0]);
        $this->assertFalse($zero->hasDiscount());
    }

    /** @test */
    public function sheet_payload_includes_discount_fields(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4320,
            'discount_percent' => 10,
            'tariff' => 'block_2',
            'status' => 'paid',
            'start_block' => 2,
            'end_block' => 2,
        ]);

        // buildPayload приватный — дёргаем через рефлексию.
        $job = new SendPaymentToSheetJob($payment->id, 'create');
        $ref = new \ReflectionMethod($job, 'buildPayload');
        $ref->setAccessible(true);
        $payload = $ref->invoke($job, $payment->fresh());

        $this->assertSame(10.0, $payload['discount_percent']);
        $this->assertSame('Скидка -10%', $payload['discount_label']);
    }

    /** @test */
    public function sheet_payload_marks_no_discount_as_empty(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 4800,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $job = new SendPaymentToSheetJob($payment->id, 'create');
        $ref = new \ReflectionMethod($job, 'buildPayload');
        $ref->setAccessible(true);
        $payload = $ref->invoke($job, $payment->fresh());

        $this->assertSame(0.0, $payload['discount_percent']);
        $this->assertSame('', $payload['discount_label']);
    }
}
