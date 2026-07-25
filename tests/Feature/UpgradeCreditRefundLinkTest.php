<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1405 C2 — атрибуция возвратов в зачёте докупки по ссылке «Возврат за
 * платёж №…» (features.upgrade_credit_refund_link, по умолчанию ВЫКЛ).
 *
 * Дефект: админ-форма обнуляет start_block/end_block при выборе «Расход»
 * (PaymentResource::afterStateUpdated), а ветка целого блока в
 * Tariff::upgradeRefundsForUser видит только возвраты с покрывающим
 * диапазоном — реальный возврат за половину блока не уменьшал зачёт при
 * докупке целого блока, и школа теряла сумму возврата второй раз.
 * Существующие netting-тесты (HalfBlockPurchaseTest) строят Расход-строки
 * С диапазоном — они проверяют логику, но не те данные, что реально
 * порождает форма.
 */
class UpgradeCreditRefundLinkTest extends TestCase
{
    use RefreshDatabase;

    private function course(): Course
    {
        return Course::factory()->create(['slug' => 'refund-link-course']);
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

    /** Расход-строка «как из админ-формы»: без диапазона, со ссылкой на исходную оплату. */
    private function formStyleRefund(User $user, Course $course, float $amount, ?int $refundOfId): Payment
    {
        return Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => $amount,
            'tariff' => 'Расход',
            'status' => 'paid',
            'refund_of_payment_id' => $refundOfId,
            // start_block/end_block отсутствуют — ровно то, что создаёт форма
            // (afterStateUpdated обнуляет их при выборе «Расход»).
        ]);
    }

    private function wholeBlock1(Course $course): Tariff
    {
        return Tariff::factory()->block(1)->create([
            'course_id' => $course->id,
            'price' => 4800,
        ]);
    }

    /**
     * Флаг ВЫКЛ — паритет: возврат «как из формы» (без диапазона) невидим
     * ветке блока, зачёт остаётся полным. Это фиксирует СЕГОДНЯШНЕЕ
     * (дефектное) поведение — доказательство прод-инертности PR при
     * выключенном флаге.
     *
     * @test
     */
    public function flag_off_form_style_refund_is_invisible_to_block_credit(): void
    {
        config(['features.upgrade_credit_refund_link' => false]);

        $course = $this->course();
        $user = User::factory()->create();
        $half = $this->pay($user, $course, 'block_1_h1', 2500);
        $this->formStyleRefund($user, $course, -2500, $half->id);

        $whole = $this->wholeBlock1($course);

        // Дефект C2 как есть: возвращённая половина всё ещё даёт полный зачёт.
        $this->assertSame(2500.0, $whole->upgradeCreditForUser($user));
        $this->assertSame(2300.0, $whole->calculateFinalPriceForUser($user));
    }

    /**
     * Флаг ВКЛ — возврат, привязанный к оплаченной половине этого блока,
     * гасит зачёт: докупка целого блока идёт по полной цене.
     *
     * @test
     */
    public function flag_on_linked_refund_cancels_credit(): void
    {
        config(['features.upgrade_credit_refund_link' => true]);

        $course = $this->course();
        $user = User::factory()->create();
        $half = $this->pay($user, $course, 'block_1_h1', 2500);
        $this->formStyleRefund($user, $course, -2500, $half->id);

        $whole = $this->wholeBlock1($course);

        $this->assertSame(0.0, $whole->upgradeCreditForUser($user));
        $this->assertSame(4800.0, $whole->calculateFinalPriceForUser($user));
    }

    /**
     * Флаг ВКЛ — частичный возврат уменьшает зачёт на свою сумму, пол — ноль.
     *
     * @test
     */
    public function flag_on_partial_linked_refund_reduces_credit_and_floors(): void
    {
        config(['features.upgrade_credit_refund_link' => true]);

        $course = $this->course();
        $user = User::factory()->create();
        $half = $this->pay($user, $course, 'block_1_h1', 2500);
        $this->formStyleRefund($user, $course, -1000, $half->id);

        $whole = $this->wholeBlock1($course);
        $this->assertSame(1500.0, $whole->upgradeCreditForUser($user));

        $this->formStyleRefund($user, $course, -5000, $half->id);
        $this->assertSame(0.0, $whole->upgradeCreditForUser($user));
    }

    /**
     * Флаг ВКЛ — возврат без диапазона И без ссылки остаётся невидимым
     * (документированный остаток C2: операционное правило — всегда заполнять
     * «Возврат за платёж №…», см. runbook §11.4 мануала).
     *
     * @test
     */
    public function flag_on_unlinked_rangeless_refund_stays_invisible(): void
    {
        config(['features.upgrade_credit_refund_link' => true]);

        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);
        $this->formStyleRefund($user, $course, -2500, null);

        $whole = $this->wholeBlock1($course);
        $this->assertSame(2500.0, $whole->upgradeCreditForUser($user));
    }

    /**
     * Флаг ВКЛ — диапазонная атрибуция (как строят существующие тесты и
     * импорт) продолжает работать без изменений.
     *
     * @test
     */
    public function flag_on_range_attributed_refund_still_nets(): void
    {
        config(['features.upgrade_credit_refund_link' => true]);

        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);
        Payment::create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'amount' => -2500,
            'tariff' => 'Расход',
            'status' => 'paid',
            'start_block' => 1,
            'end_block' => 1,
        ]);

        $whole = $this->wholeBlock1($course);
        $this->assertSame(0.0, $whole->upgradeCreditForUser($user));
    }

    /**
     * Флаг ВКЛ — возврат за половину ДРУГОГО блока не трогает зачёт этого.
     *
     * @test
     */
    public function flag_on_refund_of_other_blocks_half_does_not_affect_credit(): void
    {
        config(['features.upgrade_credit_refund_link' => true]);

        $course = $this->course();
        $user = User::factory()->create();
        $this->pay($user, $course, 'block_1_h1', 2500);
        $otherHalf = $this->pay($user, $course, 'block_2_h1', 2500);
        $this->formStyleRefund($user, $course, -2500, $otherHalf->id);

        $whole = $this->wholeBlock1($course);
        $this->assertSame(2500.0, $whole->upgradeCreditForUser($user));
    }
}
