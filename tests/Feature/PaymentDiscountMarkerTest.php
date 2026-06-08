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
    public function has_discount_and_label_reflect_stored_values(): void
    {
        $percent = new Payment(['discount_percent' => 20]);
        $this->assertTrue($percent->hasDiscount());
        $this->assertSame('-20%', $percent->discountLabel());

        $fixed = new Payment(['discount_percent' => null, 'discount_amount' => 1000]);
        $this->assertTrue($fixed->hasDiscount());
        $this->assertSame('-1 000 ₽', $fixed->discountLabel());

        $plain = new Payment(['discount_percent' => null, 'discount_amount' => null]);
        $this->assertFalse($plain->hasDiscount());
        $this->assertSame('', $plain->discountLabel());
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

        $this->assertSame('-10%', $payload['discount']);
    }

    /** @test */
    public function sheet_payload_shows_fixed_discount_in_rubles(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        // Фиксированная скидка: процента нет, только рубли.
        $payment = Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 3800,
            'discount_percent' => null,
            'discount_amount' => 1000,
            'tariff' => 'block_1',
            'status' => 'paid',
        ]);

        $job = new SendPaymentToSheetJob($payment->id, 'create');
        $ref = new \ReflectionMethod($job, 'buildPayload');
        $ref->setAccessible(true);
        $payload = $ref->invoke($job, $payment->fresh());

        $this->assertSame('-1 000 ₽', $payload['discount']);
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

        $this->assertSame('', $payload['discount']);
    }
}
