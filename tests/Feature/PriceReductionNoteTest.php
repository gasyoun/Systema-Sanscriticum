<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\MarketingSetting;
use App\Models\Payment;
use App\Models\StudentDiscount;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Подпись под итоговой ценой называет реальный источник снижения: скидку,
 * зачёт предоплаты (бронь/пробное) или докупку — а не всегда «скидку».
 */
class PriceReductionNoteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        MarketingSetting::flushCached();
    }

    private function fullTariff(Course $course, float $price = 6000): Tariff
    {
        return Tariff::create([
            'course_id' => $course->id,
            'title' => 'Весь курс',
            'type' => 'full',
            'price' => $price,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function no_reduction_returns_empty_note(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = $this->fullTariff($course);

        $this->assertSame('', $tariff->priceReductionNoteForUser($user));
    }

    /** @test */
    public function discount_only_says_discount(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = $this->fullTariff($course);

        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'type' => 'percent', 'value' => 20, 'is_active' => true,
        ]);

        $this->assertSame('Стоимость с учётом скидки', $tariff->priceReductionNoteForUser($user));
    }

    /** @test */
    public function paid_deposit_says_prepaid_not_discount(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = $this->fullTariff($course);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->assertSame('Стоимость с учётом предоплаты', $tariff->priceReductionNoteForUser($user));
    }

    /** @test */
    public function paid_trial_says_prepaid(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = $this->fullTariff($course);

        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1500,
            'tariff' => 'trial',
            'status' => 'paid',
        ]);

        $this->assertSame('Стоимость с учётом предоплаты', $tariff->priceReductionNoteForUser($user));
    }

    /** @test */
    public function discount_and_prepaid_are_combined(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = $this->fullTariff($course);

        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'type' => 'percent', 'value' => 10, 'is_active' => true,
        ]);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 1000,
            'tariff' => 'deposit',
            'status' => 'paid',
        ]);

        $this->assertSame('Стоимость с учётом скидки и предоплаты', $tariff->priceReductionNoteForUser($user));
    }
}
