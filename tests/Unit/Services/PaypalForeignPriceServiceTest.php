<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Course;
use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\StudentDiscount;
use App\Models\Tariff;
use App\Models\User;
use App\Services\Payments\PaypalForeignPriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * H3821 — published fixed EUR/USD price list. fx_rate is pinned via
 * exchangerate.host fake (same pattern as CurrencyRateProviderTest): USDRUB=100,
 * USDEUR=1.0 → both EUR and USD rate to 100 ₽/unit, so expected numbers stay
 * arithmetic-simple across the 7 live RUB tariff blocks from the H3819
 * reconciliation report (6000/3000/4800/8000/12000/16500/35000).
 */
class PaypalForeignPriceServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.exchangerate.key', 'test-key');
        Config::set('services.paypal.fixed_price_markup', 0.08);

        Http::fake([
            'api.exchangerate.host/*' => Http::response([
                'success' => true,
                'source' => 'USD',
                'quotes' => ['USDRUB' => 100.0, 'USDEUR' => 1.0],
            ]),
        ]);
    }

    private function tariff(float $price, string $type = 'full', ?int $blockNumber = null): Tariff
    {
        $course = Course::factory()->create();

        return Tariff::factory()->for($course)->create([
            'price' => $price,
            'type' => $type,
            'block_number' => $blockNumber,
            'is_active' => true,
        ]);
    }

    /** @test */
    public function non_discounted_price_is_pure_conversion_plus_markup(): void
    {
        // Every live RUB block from the H3819 report — same fx_rate (100) for both currencies.
        foreach ([6000, 3000, 4800, 8000, 12000, 16500, 35000] as $rub) {
            $tariff = $this->tariff((float) $rub);

            $result = app(PaypalForeignPriceService::class)->priceFor($tariff, 'EUR');

            $this->assertNotNull($result);
            $this->assertEqualsWithDelta(round($rub / 100 * 1.08, 2), $result['price'], 0.01, "block {$rub}");
            $this->assertTrue($result['markup_applied']);
        }
    }

    /** @test */
    public function student_discounts_active_row_excludes_the_markup(): void
    {
        $tariff = $this->tariff(8000.0);
        $user = User::factory()->create();

        StudentDiscount::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'type' => StudentDiscount::TYPE_PERCENT,
            'value' => 20,
            'is_active' => true,
        ]);

        $result = app(PaypalForeignPriceService::class)->priceFor($tariff, 'EUR', $user);

        // Discounted RUB price = 8000 * (1 - 20%) = 6400; pure conversion, NO 1.08 markup.
        $this->assertEqualsWithDelta(64.0, $result['price'], 0.01);
        $this->assertFalse($result['markup_applied']);
    }

    /** @test */
    public function student_discounts_fixed_value_row_also_excludes_the_markup(): void
    {
        $tariff = $this->tariff(8000.0);
        $user = User::factory()->create();

        StudentDiscount::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'type' => StudentDiscount::TYPE_FIXED,
            'value' => 3000,
            'is_active' => true,
        ]);

        $result = app(PaypalForeignPriceService::class)->priceFor($tariff, 'USD', $user);

        // Discounted RUB price = 8000 - 3000 = 5000; pure conversion, no markup.
        $this->assertEqualsWithDelta(50.0, $result['price'], 0.01);
        $this->assertFalse($result['markup_applied']);
    }

    /** @test */
    public function inactive_discount_row_does_not_exclude_the_markup(): void
    {
        $tariff = $this->tariff(8000.0);
        $user = User::factory()->create();

        StudentDiscount::create([
            'user_id' => $user->id,
            'course_id' => $tariff->course_id,
            'type' => StudentDiscount::TYPE_PERCENT,
            'value' => 20,
            'is_active' => false,
        ]);

        $result = app(PaypalForeignPriceService::class)->priceFor($tariff, 'EUR', $user);

        $this->assertTrue($result['markup_applied']);
        $this->assertEqualsWithDelta(round(8000 / 100 * 1.08, 2), $result['price'], 0.01);
    }

    /** @test */
    public function refresh_all_writes_the_fixed_table_and_dry_run_does_not(): void
    {
        $tariff = $this->tariff(6000.0);

        $rows = app(PaypalForeignPriceService::class)->refreshAll(dryRun: true);

        $this->assertDatabaseCount('tariff_foreign_prices', 0);
        $this->assertNotEmpty($rows);

        app(PaypalForeignPriceService::class)->refreshAll(dryRun: false);

        $this->assertDatabaseHas('tariff_foreign_prices', [
            'tariff_id' => $tariff->id,
            'currency' => 'EUR',
        ]);
        $this->assertDatabaseHas('tariff_foreign_prices', [
            'tariff_id' => $tariff->id,
            'currency' => 'USD',
        ]);
    }

    /** @test */
    public function refresh_all_skips_a_tariff_with_no_fx_rate_available(): void
    {
        Config::set('services.exchangerate.key', null);
        $this->tariff(6000.0);

        $rows = app(PaypalForeignPriceService::class)->refreshAll(dryRun: false);

        $this->assertTrue(collect($rows)->every(fn (array $r) => $r['skipped'] === true));
        $this->assertDatabaseCount('tariff_foreign_prices', 0);
    }

    /** @test */
    public function refresh_all_never_touches_payments_or_payment_audits(): void
    {
        $this->tariff(6000.0);
        $this->tariff(8000.0, 'block', 2);

        app(PaypalForeignPriceService::class)->refreshAll(dryRun: false);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, PaymentAudit::query()->count());
    }
}
