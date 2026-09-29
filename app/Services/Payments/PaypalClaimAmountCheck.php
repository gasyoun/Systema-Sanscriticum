<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\Tariff;
use App\Models\User;

/**
 * H5442 (P0, D4 + D20): сверка заявленной PayPal-суммы с ожидаемой.
 *
 * Ожидаемая цена — та же, что форма показала ученику (published fixed price
 * list при `features.paypal_fixed_price_list`, иначе ручной config блочных
 * цен). Валюта обязательна: нет ожидаемой цены в заявленной валюте — нет
 * автоподтверждения.
 *
 *  - exact              — совпало до цента → автоподтверждение;
 *  - underpaid_within_5 — недоплата не более 5% от счёта → засчитывается,
 *                         ученик уведомляется о разнице (D4);
 *  - overpaid_within_5  — переплата не более 5% → засчитывается, разница
 *                         только документируется: без долга, без ручной
 *                         проверки, без зачёта в будущее (D20);
 *  - beyond_5           — отклонение больше 5% → остаётся pending;
 *  - no_expected_price  — ожидаемой цены в этой валюте нет → pending.
 *
 * Арифметика в центах (целые), 5% считаются от суммы счёта (D20).
 */
final class PaypalClaimAmountCheck
{
    public const EXACT = 'exact';

    public const UNDERPAID_WITHIN = 'underpaid_within_5';

    public const OVERPAID_WITHIN = 'overpaid_within_5';

    public const BEYOND = 'beyond_5';

    public const NO_EXPECTED = 'no_expected_price';

    public const TOLERANCE_PCT = 5;

    public function __construct(private readonly PaypalForeignPriceService $prices) {}

    public function expectedPrice(Tariff $tariff, string $currency, ?User $user): ?float
    {
        $currency = strtoupper($currency);

        if (config('features.paypal_fixed_price_list')) {
            $price = $this->prices->priceFor($tariff, $currency, $user)['price'] ?? null;

            return $price !== null && (float) $price > 0 ? (float) $price : null;
        }

        if ($tariff->type !== 'block') {
            return null;
        }

        $legacy = config('services.paypal.foreign_block_prices')[$tariff->course_id] ?? null;
        $price = is_array($legacy) ? ($legacy[strtolower($currency)] ?? null) : null;

        return $price !== null && (float) $price > 0 ? (float) $price : null;
    }

    /**
     * @return array{verdict:string, currency:string, claimed:float, expected:?float, diff:?float, deviation_pct:?float, auto_confirm:bool, notify_underpayment:bool}
     */
    public function check(Tariff $tariff, string $currency, float $claimed, ?User $user): array
    {
        return self::classify(strtoupper($currency), $claimed, $this->expectedPrice($tariff, $currency, $user));
    }

    /**
     * @return array{verdict:string, currency:string, claimed:float, expected:?float, diff:?float, deviation_pct:?float, auto_confirm:bool, notify_underpayment:bool}
     */
    public static function classify(string $currency, float $claimed, ?float $expected): array
    {
        $claimedCents = (int) round($claimed * 100);

        if ($expected === null || $expected <= 0) {
            return [
                'verdict' => self::NO_EXPECTED,
                'currency' => $currency,
                'claimed' => $claimedCents / 100,
                'expected' => null,
                'diff' => null,
                'deviation_pct' => null,
                'auto_confirm' => false,
                'notify_underpayment' => false,
            ];
        }

        $expectedCents = (int) round($expected * 100);
        $diffCents = $claimedCents - $expectedCents;
        $withinTolerance = abs($diffCents) * 100 <= $expectedCents * self::TOLERANCE_PCT;

        $verdict = match (true) {
            $diffCents === 0 => self::EXACT,
            ! $withinTolerance => self::BEYOND,
            $diffCents < 0 => self::UNDERPAID_WITHIN,
            default => self::OVERPAID_WITHIN,
        };

        return [
            'verdict' => $verdict,
            'currency' => $currency,
            'claimed' => $claimedCents / 100,
            'expected' => $expectedCents / 100,
            'diff' => $diffCents / 100,
            'deviation_pct' => round($diffCents / $expectedCents * 100, 2),
            'auto_confirm' => $verdict !== self::BEYOND,
            'notify_underpayment' => $verdict === self::UNDERPAID_WITHIN,
        ];
    }

    /**
     * Стабильный ключ повтора заявки. С txn — сам PayPal-перевод (глобально
     * уникален у PayPal: один перевод нельзя заявить дважды ни тем же, ни
     * другим учеником, ни на другой тариф). Без txn — ученик + тариф + дата
     * перевода + валюта + сумма.
     *
     * $scope разводит без-txn ключи разных форм одного тарифа (доплата
     * `supplement` vs полная цена блока); пустой scope = ключ H5442 без
     * изменений. С txn scope не участвует: перевод один на все формы.
     */
    public static function replayKey(int $userId, int $tariffId, ?string $txn, string $paidOn, string $currency, float $amount, string $scope = ''): string
    {
        $txn = strtoupper(trim((string) $txn));

        $noTxn = $scope === '' ? 'paypal-claim|no-txn' : 'paypal-claim|no-txn|'.$scope;
        $material = $txn !== ''
            ? 'paypal-claim|txn|'.$txn
            : implode('|', [$noTxn, $userId, $tariffId, $paidOn, strtoupper($currency), (int) round($amount * 100)]);

        return hash('sha256', $material);
    }
}
