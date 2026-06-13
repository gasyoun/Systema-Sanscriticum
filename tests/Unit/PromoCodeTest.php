<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PromoCode;
use Tests\TestCase;

/**
 * PromoCode — чистая денежная логика (валидность + расчёт скидки), без БД.
 * 100%-промокод порождает «оплаченный» Payment в чекауте, поэтому регрессия
 * здесь раздаёт курсы бесплатно или считает неверную сумму.
 */
class PromoCodeTest extends TestCase
{
    private function promo(array $attrs): PromoCode
    {
        return new PromoCode(array_merge([
            'code' => 'TEST',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ], $attrs));
    }

    /** @test */
    public function inactive_code_is_not_valid(): void
    {
        $this->assertFalse($this->promo(['is_active' => false])->isValid());
    }

    /** @test */
    public function code_at_usage_limit_is_not_valid(): void
    {
        $this->assertFalse($this->promo(['usage_limit' => 5, 'used_count' => 5])->isValid());
        $this->assertTrue($this->promo(['usage_limit' => 5, 'used_count' => 4])->isValid());
    }

    /** @test */
    public function expired_code_is_not_valid(): void
    {
        $this->assertFalse($this->promo(['expires_at' => now()->subDay()])->isValid());
        $this->assertTrue($this->promo(['expires_at' => now()->addDay()])->isValid());
    }

    /** @test */
    public function active_unlimited_unexpired_code_is_valid(): void
    {
        $this->assertTrue($this->promo([])->isValid());
    }

    /** @test */
    public function percent_discount_is_applied(): void
    {
        $this->assertEquals(800.0, $this->promo(['type' => 'percent', 'value' => 20])->calculateDiscountedPrice(1000.0));
    }

    /** @test */
    public function fixed_discount_is_applied(): void
    {
        $this->assertEquals(700.0, $this->promo(['type' => 'fixed', 'value' => 300])->calculateDiscountedPrice(1000.0));
    }

    /** @test */
    public function discount_never_pushes_price_below_zero(): void
    {
        $this->assertEquals(0, $this->promo(['type' => 'fixed', 'value' => 5000])->calculateDiscountedPrice(1000.0));
    }
}
