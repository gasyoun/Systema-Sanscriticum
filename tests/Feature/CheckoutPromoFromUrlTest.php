<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\PromoCode;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutPromoFromUrlTest extends TestCase
{
    use RefreshDatabase;

    private function tariff(float $price = 12000): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->create([
            'course_id' => $course->id,
            'price' => $price,
        ]);
    }

    /** @test */
    public function valid_promo_in_url_is_applied_on_first_render(): void
    {
        $tariff = $this->tariff(12000);
        PromoCode::create([
            'code' => 'AUTUMN10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $response = $this->get("/checkout/{$tariff->id}?promo=AUTUMN10");

        $response->assertOk();
        // Промокод подхвачен серверным стейтом (Alpine инициализируется из него).
        $response->assertSee('AUTUMN10');
        // 12000 − 10% = 10800 (итоговая цена в @json внутри скрипта).
        $response->assertSee('10800', false);
        $this->assertSame('AUTUMN10', session('promo_code'));
    }

    /** @test */
    public function promo_code_is_case_insensitive_in_url(): void
    {
        $tariff = $this->tariff(12000);
        PromoCode::create([
            'code' => 'AUTUMN10',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
        ]);

        $this->get("/checkout/{$tariff->id}?promo=autumn10")->assertOk();

        $this->assertSame('AUTUMN10', session('promo_code'));
    }

    /** @test */
    public function invalid_promo_in_url_does_not_break_page_and_keeps_full_price(): void
    {
        $tariff = $this->tariff(12000);

        $response = $this->get("/checkout/{$tariff->id}?promo=NOPE");

        $response->assertOk();
        $response->assertSee('12000', false);
        $this->assertNull(session('promo_code'));
    }

    /** @test */
    public function inactive_promo_in_url_is_ignored(): void
    {
        $tariff = $this->tariff(12000);
        PromoCode::create([
            'code' => 'OLD',
            'type' => 'percent',
            'value' => 50,
            'is_active' => false,
        ]);

        $response = $this->get("/checkout/{$tariff->id}?promo=OLD");

        $response->assertOk();
        $this->assertNull(session('promo_code'));
    }

    /** @test */
    public function course_bound_promo_applies_on_its_own_course(): void
    {
        $tariff = $this->tariff(12000);
        PromoCode::create([
            'code' => 'COURSEA',
            'type' => 'percent',
            'value' => 10,
            'is_active' => true,
            'course_id' => $tariff->course_id,
        ]);

        $response = $this->get("/checkout/{$tariff->id}?promo=COURSEA");

        $response->assertOk();
        $response->assertSee('10800', false); // 12000 − 10%
        $this->assertSame('COURSEA', session('promo_code'));
    }

    /** @test */
    public function course_bound_promo_in_url_is_ignored_on_other_course(): void
    {
        $tariff = $this->tariff(12000);
        $otherCourse = Course::factory()->create();
        PromoCode::create([
            'code' => 'COURSEB',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
            'course_id' => $otherCourse->id,
        ]);

        $response = $this->get("/checkout/{$tariff->id}?promo=COURSEB");

        $response->assertOk();
        $response->assertSee('12000', false); // полная цена, скидки нет
        $this->assertNull(session('promo_code'));
    }

    /** @test */
    public function apply_promo_rejects_code_bound_to_other_course(): void
    {
        $tariff = $this->tariff(12000);
        $otherCourse = Course::factory()->create();
        PromoCode::create([
            'code' => 'COURSEB',
            'type' => 'percent',
            'value' => 50,
            'is_active' => true,
            'course_id' => $otherCourse->id,
        ]);

        $this->postJson("/checkout/{$tariff->id}/promo", ['code' => 'COURSEB'])
            ->assertStatus(422)
            ->assertJson(['ok' => false]);

        $this->assertNull(session('promo_code'));
    }

    /** @test */
    public function global_promo_applies_to_any_course(): void
    {
        $tariff = $this->tariff(12000);
        PromoCode::create([
            'code' => 'GLOBAL',
            'type' => 'percent',
            'value' => 25,
            'is_active' => true,
            'course_id' => null,
        ]);

        $this->postJson("/checkout/{$tariff->id}/promo", ['code' => 'GLOBAL'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertSame('GLOBAL', session('promo_code'));
    }
}
