<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\StudentDiscount;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndividualDiscountTest extends TestCase
{
    use RefreshDatabase;

    private function tariff(Course $course, float $price = 1000, string $type = 'full'): Tariff
    {
        return Tariff::create([
            'course_id' => $course->id,
            'title' => $type === 'block' ? 'Блок 1' : 'Весь курс',
            'type' => $type,
            'block_number' => $type === 'block' ? 1 : null,
            'price' => $price,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function apply_handles_percent_fixed_and_floor(): void
    {
        $percent = new StudentDiscount(['type' => 'percent', 'value' => 80]);
        $this->assertEquals(200.0, $percent->apply(1000));

        $fixed = new StudentDiscount(['type' => 'fixed', 'value' => 800]);
        $this->assertEquals(200.0, $fixed->apply(1000));

        // Не уходит в минус.
        $this->assertEquals(0.0, (new StudentDiscount(['type' => 'fixed', 'value' => 800]))->apply(600));
    }

    /** @test */
    public function personal_discount_overrides_loyalty(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        // Тариф с принудительной «лояльностью» 20% — проверяем, что персональная
        // скидка применяется ВМЕСТО неё, не консультируясь с getDiscountPercentForUser.
        $tariff = new class extends Tariff
        {
            protected $table = 'tariffs';

            public function getDiscountPercentForUser($user): int
            {
                return 20;
            }
        };
        $tariff->fill(['course_id' => $course->id, 'title' => 'Весь курс', 'type' => 'full', 'price' => 1000, 'is_active' => true]);
        $tariff->save();

        // Без персональной — действует лояльность 20% → 800.
        $this->assertEquals(800.0, $tariff->calculateFinalPriceForUser($user));

        // С персональной 50% — лояльность игнорируется → 500.
        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'type' => 'percent', 'value' => 50, 'is_active' => true,
        ]);

        $this->assertEquals(500.0, $tariff->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function fixed_discount_applies_to_block_tariff(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $block = $this->tariff($course, 1000, 'block');

        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'type' => 'fixed', 'value' => 800, 'is_active' => true,
        ]);

        $this->assertEquals(200.0, $block->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function inactive_discount_is_ignored(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = $this->tariff($course, 1000);

        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'type' => 'percent', 'value' => 50, 'is_active' => false,
        ]);

        // Скидка неактивна, лояльности нет → полная цена.
        $this->assertEquals(1000.0, $tariff->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function deposit_credit_applies_on_top_of_personal_discount(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $tariff = $this->tariff($course, 1000);

        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'type' => 'percent', 'value' => 50, 'is_active' => true,
        ]);
        // Неизрасходованный депозит 100 ₽ по этому курсу.
        Payment::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'amount' => 100, 'tariff' => 'deposit', 'status' => 'paid',
        ]);

        // 1000 → −50% = 500 → −100 (депозит) = 400.
        $this->assertEquals(400.0, $tariff->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function active_for_scopes_by_user_and_course(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();
        $stranger = User::factory()->create();

        $d = StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'type' => 'fixed', 'value' => 300, 'is_active' => true,
        ]);

        $this->assertEquals($d->id, StudentDiscount::activeFor($user->id, $course->id)?->id);
        $this->assertNull(StudentDiscount::activeFor($stranger->id, $course->id));
        $this->assertNull(StudentDiscount::activeFor($user->id, Course::factory()->create()->id));
    }
}
