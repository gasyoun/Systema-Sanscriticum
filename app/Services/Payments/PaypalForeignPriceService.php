<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\StudentDiscount;
use App\Models\Tariff;
use App\Models\TariffForeignPrice;
use App\Models\User;
use App\Services\CurrencyRateProvider;
use Illuminate\Support\Carbon;

/**
 * H3821 — published, fixed EUR/USD price per tariff, replacing the ad hoc
 * per-transaction manual quoting `services.paypal.foreign_block_prices` (H3819
 * reconciliation: same RUB tariff quoted 0-18% apart across payers).
 *
 * Formula for a non-discounted payer: round(tariff.price / fx_rate * markup, 2),
 * fx_rate = ₽ per unit currency from CurrencyRateProvider (exchangerate.host,
 * already the school's own FX source for teacher payouts — see that class).
 * markup = services.paypal.fixed_price_markup (default 0.08).
 *
 * Carve-out (MG ruling): a payer with an ACTIVE student_discounts row for this
 * tariff's course/block is excluded from the markup — their price is the
 * already-discounted RUB price converted at the pure fx_rate, no 8% on top.
 * This is why the fixed table (refreshed monthly by refreshAll()) only ever
 * holds the non-discounted price; a discounted seat is always computed live,
 * never read from tariff_foreign_prices.
 */
class PaypalForeignPriceService
{
    public const CURRENCIES = ['EUR', 'USD'];

    /**
     * MG ruling 02-09-2026: after the base formula the price is stepped UP to a
     * round figure landing in a +3–6% corridor (8 000 ₽ → €85.88 becomes €90;
     * $99.51 becomes $105 — never the bare round-up $100). Round grid: multiples
     * of 5 at ≥50, integers below. When no round figure fits the +3–6% corridor,
     * fall back to the smallest round figure ≥ the computed price (never below).
     */
    private const NICE_CORRIDOR_MIN = 1.03;
    private const NICE_CORRIDOR_MAX = 1.06;

    public function __construct(private readonly CurrencyRateProvider $rates) {}

    /**
     * Recompute and (unless $dryRun) persist the fixed price for every active
     * tariff × currency. Returns one row per tariff×currency attempted,
     * including ones skipped for a missing fx rate — the dry-run report a
     * human reviews before flipping the flag.
     *
     * Never touches payments/payment_audits — reads Tariff only, writes only
     * tariff_foreign_prices.
     *
     * @return array<int, array{tariff_id:int,course_id:?int,title:?string,currency:string,rub_price:float,fx_rate:?float,price:?float,skipped:bool}>
     */
    public function refreshAll(bool $dryRun = false, ?Carbon $date = null): array
    {
        $date ??= now();
        $markup = (float) config('services.paypal.fixed_price_markup', 0.08);
        $rows = [];

        Tariff::query()->where('is_active', true)->with('course')->each(function (Tariff $tariff) use ($date, $markup, $dryRun, &$rows) {
            foreach (self::CURRENCIES as $currency) {
                $fxRate = $this->rates->rublesPerUnit($currency, $date);
                $rubPrice = (float) $tariff->price;

                if ($fxRate === null || $fxRate <= 0) {
                    $rows[] = [
                        'tariff_id' => $tariff->id,
                        'course_id' => $tariff->course_id,
                        'title' => $tariff->title,
                        'currency' => $currency,
                        'rub_price' => $rubPrice,
                        'fx_rate' => null,
                        'price' => null,
                        'skipped' => true,
                    ];

                    continue;
                }

                $price = self::roundUpToNice(round($rubPrice / $fxRate * (1 + $markup), 2));

                $rows[] = [
                    'tariff_id' => $tariff->id,
                    'course_id' => $tariff->course_id,
                    'title' => $tariff->title,
                    'currency' => $currency,
                    'rub_price' => $rubPrice,
                    'fx_rate' => $fxRate,
                    'price' => $price,
                    'skipped' => false,
                ];

                if (! $dryRun) {
                    TariffForeignPrice::query()->updateOrCreate(
                        ['tariff_id' => $tariff->id, 'currency' => $currency],
                        ['price' => $price, 'fx_rate' => $fxRate, 'computed_at' => $date],
                    );
                }
            }
        });

        return $rows;
    }

    /**
     * Price to show/charge for one tariff × currency × (optional) payer.
     * Null when no fx rate is available at all (published table empty AND
     * live lookup failed) — the caller falls back to the pre-H3821 manual
     * config path while the flag is dark, or hides the price if flag is on.
     *
     * @return array{price:float,fx_rate:float,markup_applied:bool}|null
     */
    public function priceFor(Tariff $tariff, string $currency, ?User $user = null): ?array
    {
        $currency = strtoupper($currency);
        if (! in_array($currency, self::CURRENCIES, true)) {
            return null;
        }

        $hasActiveDiscount = $user && $tariff->course_id
            ? StudentDiscount::activeFor($user->id, $tariff->course_id, $tariff->block_number) !== null
            : false;

        if ($hasActiveDiscount) {
            $rubPrice = $tariff->priceAfterDiscountForUser($user);
            $fxRate = $this->currentFxRate($tariff, $currency);
            if ($fxRate === null) {
                return null;
            }

            return [
                'price' => round($rubPrice / $fxRate, 2),
                'fx_rate' => $fxRate,
                'markup_applied' => false,
            ];
        }

        $fixed = TariffForeignPrice::query()
            ->where('tariff_id', $tariff->id)
            ->where('currency', $currency)
            ->first();

        if ($fixed) {
            return [
                'price' => (float) $fixed->price,
                'fx_rate' => (float) $fixed->fx_rate,
                'markup_applied' => true,
            ];
        }

        // Published table doesn't have this tariff yet (not refreshed since
        // creation) — compute live so the page never silently shows nothing.
        $fxRate = $this->currentFxRate($tariff, $currency);
        if ($fxRate === null) {
            return null;
        }

        $markup = (float) config('services.paypal.fixed_price_markup', 0.08);

        return [
            'price' => self::roundUpToNice(round((float) $tariff->price / $fxRate * (1 + $markup), 2)),
            'fx_rate' => $fxRate,
            'markup_applied' => true,
        ];
    }

    /**
     * Round-grid step-up inside the +3–6% corridor (see class docblock). Grid:
     * multiples of 5 for prices ≥ 50, integers below. Corridor miss → smallest
     * round figure ≥ price, so the published price is always ≥ the formula.
     */
    public static function roundUpToNice(float $price): float
    {
        $step = $price >= 50.0 ? 5.0 : 1.0;
        $ceilToStep = fn (float $value): float => ceil($value / $step) * $step;

        $corridorCandidate = $ceilToStep($price * self::NICE_CORRIDOR_MIN);
        if ($corridorCandidate <= $price * self::NICE_CORRIDOR_MAX) {
            return $corridorCandidate;
        }

        return $ceilToStep($price);
    }

    /** Prefer the fx_rate already published for this tariff (eyeball consistency); else fetch live. */
    private function currentFxRate(Tariff $tariff, string $currency): ?float
    {
        $fixed = TariffForeignPrice::query()
            ->where('tariff_id', $tariff->id)
            ->where('currency', $currency)
            ->first();

        if ($fixed) {
            return (float) $fixed->fx_rate;
        }

        return $this->rates->rublesPerUnit($currency, now());
    }
}
