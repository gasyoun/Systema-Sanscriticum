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
    public function block_specific_discount_overrides_course_wide_default(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        // Постоянная скидка 10% на все блоки + 20% именно на блок 2.
        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'block_number' => null, 'type' => 'percent', 'value' => 10, 'is_active' => true,
        ]);
        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'block_number' => 2, 'type' => 'percent', 'value' => 20, 'is_active' => true,
        ]);

        $block1 = Tariff::create(['course_id' => $course->id, 'title' => 'Блок 1', 'type' => 'block', 'block_number' => 1, 'price' => 1000, 'is_active' => true]);
        $block2 = Tariff::create(['course_id' => $course->id, 'title' => 'Блок 2', 'type' => 'block', 'block_number' => 2, 'price' => 1000, 'is_active' => true]);
        $full = $this->tariff($course, 1000, 'full');

        // Блок 2 — своя скидка 20% → 800.
        $this->assertEquals(800.0, $block2->calculateFinalPriceForUser($user));
        // Блок 1 — общая 10% → 900.
        $this->assertEquals(900.0, $block1->calculateFinalPriceForUser($user));
        // Весь курс (block_number null) — общая 10% → 900.
        $this->assertEquals(900.0, $full->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function block_specific_discount_does_not_leak_to_other_blocks(): void
    {
        $user = User::factory()->create();
        $course = Course::factory()->create();

        // Только блок-специфичная скидка (без общей) — на блок 3.
        StudentDiscount::create([
            'user_id' => $user->id, 'course_id' => $course->id,
            'block_number' => 3, 'type' => 'percent', 'value' => 50, 'is_active' => true,
        ]);

        $block1 = Tariff::create(['course_id' => $course->id, 'title' => 'Блок 1', 'type' => 'block', 'block_number' => 1, 'price' => 1000, 'is_active' => true]);
        $block3 = Tariff::create(['course_id' => $course->id, 'title' => 'Блок 3', 'type' => 'block', 'block_number' => 3, 'price' => 1000, 'is_active' => true]);

        // Блок 3 — 50% → 500; блок 1 — нет общей скидки → полная цена 1000.
        $this->assertEquals(500.0, $block3->calculateFinalPriceForUser($user));
        $this->assertEquals(1000.0, $block1->calculateFinalPriceForUser($user));

        // activeFor с номером блока выбирает блок-специфичную, без него — null (общей нет).
        $this->assertNotNull(StudentDiscount::activeFor($user->id, $course->id, 3));
        $this->assertNull(StudentDiscount::activeFor($user->id, $course->id, 1));
        $this->assertNull(StudentDiscount::activeFor($user->id, $course->id));
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
