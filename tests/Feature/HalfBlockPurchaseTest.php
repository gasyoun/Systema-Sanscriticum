<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Продажа блока «по половинам»: ключ доступа block_N_hH, проверка доступа к урокам
 * по половине и зачёт уплаченного при докупке целого блока / курса.
 */
class HalfBlockPurchaseTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        return Course::factory()->create(['slug' => 'half-block-course']);
    }

    /** Урок блока с указанной половиной (group_id = null → виден всем группам). */
    private function lesson(Course $course, int $block, ?int $half): Lesson
    {
        return Lesson::factory()->create([
            'course_id' => $course->id,
            'block_number' => $block,
            'block_half' => $half,
        ]);
    }

    private function pay(User $user, Course $course, string $tariffKey, float $amount): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => $tariffKey,
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function tariff_access_key_reflects_type_and_half(): void
    {
        $full = Tariff::factory()->create();
        $whole = Tariff::factory()->block(1)->create();
        $half = Tariff::factory()->block(1)->create(['block_half' => 2]);

        $this->assertSame('full', $full->accessKey());
        $this->assertSame('block_1', $whole->accessKey());
        $this->assertSame('block_1_h2', $half->accessKey());
    }

    /** @test */
    public function lesson_unlocking_respects_half_and_whole_block(): void
    {
        $course = $this->course();
        $h1 = $this->lesson($course, 1, 1);
        $whole = $this->lesson($course, 1, null);

        // Урок 1-й половины: открывается своей половиной, целым блоком и full.
        $this->assertTrue($h1->isUnlockedBy(['block_1_h1']));
        $this->assertTrue($h1->isUnlockedBy(['block_1']));
        $this->assertTrue($h1->isUnlockedBy(['full']));
        $this->assertFalse($h1->isUnlockedBy(['block_1_h2']));

        // Неразбитый урок блока: половина его НЕ открывает, только целый блок / full.
        $this->assertFalse($whole->isUnlockedBy(['block_1_h1']));
        $this->assertTrue($whole->isUnlockedBy(['block_1']));
    }

    /** @test */
    public function buying_first_half_opens_only_its_lessons(): void
    {
        $course = $this->course();
        $h1 = $this->lesson($course, 1, 1);
        $h2 = $this->lesson($course, 1, 2);

        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);

        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $h1->id]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('student.lesson', [$course->slug, $h2->id]))
            ->assertRedirect(route('student.course', $course->slug));
    }

    /** @test */
    public function buying_whole_block_opens_both_halves(): void
    {
        $course = $this->course();
        $h1 = $this->lesson($course, 1, 1);
        $h2 = $this->lesson($course, 1, 2);

        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1', 4800);

        $this->actingAs($user)->get(route('student.lesson', [$course->slug, $h1->id]))->assertOk();
        $this->actingAs($user)->get(route('student.lesson', [$course->slug, $h2->id]))->assertOk();
    }

    /** @test */
    public function whole_block_price_credits_already_paid_half(): void
    {
        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);

        $wholeTariff = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 4800,
        ]);

        // 4800 − 2500 = 2300
        $this->assertSame(2300.0, $wholeTariff->calculateFinalPriceForUser($user));
        $this->assertSame(2500.0, $wholeTariff->upgradeCreditForUser($user));
    }

    /** @test */
    public function full_course_price_credits_all_paid_blocks_and_halves(): void
    {
        // Зачёт блоков в полный курс — за фича-флагом; здесь проверяем включённое поведение.
        config(['features.full_course_block_credit' => true]);

        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);
        $this->pay($user, $course, 'block_2', 4800);

        $fullTariff = Tariff::factory()->create([
            'course_id' => $course->id,
            'price' => 12000,
        ]);

        // 12000 − (2500 + 4800) = 4700
        $this->assertSame(4700.0, $fullTariff->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function full_course_does_not_credit_paid_blocks_when_flag_off(): void
    {
        // Дефолт: флаг выключен → уже купленные блоки НЕ вычитаются из полного курса.
        config(['features.full_course_block_credit' => false]);

        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);
        $this->pay($user, $course, 'block_2', 4800);

        $fullTariff = Tariff::factory()->create([
            'course_id' => $course->id,
            'price' => 12000,
        ]);

        // Зачёта нет → полная цена.
        $this->assertSame(0.0, $fullTariff->upgradeCreditForUser($user));
        $this->assertSame(12000.0, $fullTariff->calculateFinalPriceForUser($user));

        // Зачёт «половина → целый блок» при этом продолжает работать.
        $wholeBlock1 = Tariff::factory()->block(1)->create(['course_id' => $course->id, 'price' => 4800]);
        $this->assertSame(2500.0, $wholeBlock1->upgradeCreditForUser($user));
    }

    /** @test */
    public function second_half_is_not_discounted_by_first_half(): void
    {
        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);

        $secondHalf = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 2500,
            'block_half' => 2,
        ]);

        // Половины не пересекаются — зачёта между ними нет.
        $this->assertSame(0.0, $secondHalf->upgradeCreditForUser($user));
        $this->assertSame(2500.0, $secondHalf->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function upgrade_credit_does_not_leak_across_courses(): void
    {
        // Половина, оплаченная на ДРУГОМ курсе, не засчитывается при покупке
        // целого блока на этом курсе — зачёт строго по course_id.
        $course = $this->course();
        $other = Course::factory()->create(['slug' => 'other-course']);

        $user = User::factory()->create();
        $this->pay($user, $other, 'block_1_h1', 2500); // оплата на чужом курсе

        $wholeBlock1 = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 4800,
        ]);

        $this->assertSame(0.0, $wholeBlock1->upgradeCreditForUser($user));
        $this->assertSame(4800.0, $wholeBlock1->calculateFinalPriceForUser($user));
    }

    /** @test */
    public function upgrade_credit_ignores_non_paid_payments(): void
    {
        // Половина в статусе pending (не оплачена) не даёт зачёта — только paid().
        $course = $this->course();
        $user = User::factory()->create();
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => 2500,
            'tariff' => 'block_1_h1',
            'status' => 'pending',
        ]);

        $wholeBlock1 = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 4800,
        ]);

        $this->assertSame(0.0, $wholeBlock1->upgradeCreditForUser($user));
    }

    /** @test */
    public function vip_and_bundle_tariffs_give_no_upgrade_credit(): void
    {
        // vip/bundle — самостоятельные продукты, не контейнеры блоков: зачёта нет,
        // даже когда блоки этого курса уже оплачены.
        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1', 4800);

        foreach (['vip', 'bundle'] as $type) {
            $tariff = Tariff::factory()->create([
                'course_id' => $course->id,
                'type' => $type,
                'price' => 12000,
            ]);

            $this->assertSame(0.0, $tariff->upgradeCreditForUser($user), "type=$type");
        }
    }

    /** @test */
    public function credit_exceeding_price_floors_at_zero_not_negative(): void
    {
        // Зачёт за две оплаченные половины (2500+2500) превышает уценённую целым
        // блоком цену — итог не уходит в минус, а упирается в 0 (free-access).
        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);
        $this->pay($user, $course, 'block_1_h2', 2500);

        $wholeBlock1 = Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 4000, // дешевле суммы половин (5000) — кредит «перекрывает» цену
        ]);

        $this->assertSame(5000.0, $wholeBlock1->upgradeCreditForUser($user));
        $this->assertSame(0.0, $wholeBlock1->calculateFinalPriceForUser($user));
    }
}
