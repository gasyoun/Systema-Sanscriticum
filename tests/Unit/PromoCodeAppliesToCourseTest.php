<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\PromoCode;
use PHPUnit\Framework\TestCase;

/**
 * Контракт PromoCode::appliesToCourse() — единый источник правды о привязке
 * промокода к курсу (используется и в CheckoutController, и в PaymentController).
 */
class PromoCodeAppliesToCourseTest extends TestCase
{
    /** @test */
    public function global_code_applies_to_any_course(): void
    {
        $promo = new PromoCode(['course_id' => null]);

        $this->assertTrue($promo->appliesToCourse(1));
        $this->assertTrue($promo->appliesToCourse(999));
        $this->assertTrue($promo->appliesToCourse(null));
    }

    /** @test */
    public function bound_code_applies_only_to_its_course(): void
    {
        $promo = new PromoCode(['course_id' => 5]);

        $this->assertTrue($promo->appliesToCourse(5));
        $this->assertFalse($promo->appliesToCourse(6));
        $this->assertFalse($promo->appliesToCourse(null));
    }
}
