<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Course;
use App\Models\Payment;
use App\Models\Tariff;
use App\Models\User;

/**
 * H4443 (MG 09-09): «деньги ещё» по канве — неоплаченные блоки по факт-платежам.
 *
 * Правила: блок считается покрытым, если его закрывает активный платёж
 * (Payment::coversBlockHalf, PAID_STATUSES, 'full' закрывает всё); полблока =
 * половина цены. Цена блока — базовый Tariff (start_block/end_block/price),
 * фолбэк — цена курса / число блоков с пометкой оценки. Скидки/иностранная
 * валюта/прана НЕ входят — цифра «потенциал по базовым ценам».
 * В публичный Telegram-пост деньги не попадают (только админка).
 */
final class CanvasMoney
{
    /**
     * Неоплаченный остаток студента по курсу.
     *
     * @return array{amount: float, blocks_from: int, blocks_to: int, estimated: bool}|null null — не грамматика-канва
     */
    public static function unpaidFor(User $user, Course $course, int $cursorBlock, int $blocksTotal): ?array
    {
        if ($blocksTotal <= 0 || $cursorBlock >= $blocksTotal) {
            return ['amount' => 0.0, 'blocks_from' => 0, 'blocks_to' => 0, 'estimated' => false];
        }

        $from = $cursorBlock + 1;
        $to = $blocksTotal;

        $paid = Payment::query()
            ->where('user_id', $user->id)
            ->where('course_id', $course->id)
            ->paid()
            ->get();

        $tariffs = Tariff::query()->where('course_id', $course->id)->get();
        $fallbackPrice = self::fallbackBlockPrice($tariffs, $blocksTotal, $course);

        $amount = 0.0;
        for ($block = $from; $block <= $to; $block++) {
            $amount += self::blockRemaining($paid, $tariffs, $block, $fallbackPrice);
        }

        return ['amount' => $amount, 'blocks_from' => $from, 'blocks_to' => $to, 'estimated' => $fallbackPrice !== null];
    }

    /**
     * Остаток по одному блоку в рублях (полное покрытие → 0; одно из двух
     * покрытий → половина; никакое → полная цена блока).
     *
     * @param  iterable<int, Payment>  $paid
     * @param  iterable<int, Tariff>  $tariffs
     */
    private static function blockRemaining(iterable $paid, iterable $tariffs, int $block, ?float $fallback): float
    {
        $full = false;
        $half1 = false;
        $half2 = false;
        foreach ($paid as $payment) {
            if ($payment->coversBlockHalf($block, 1)) {
                $full = $full || $payment->coversBlockHalf($block, 2) || self::paymentIsWholeBlock($payment);
                $half1 = true;
            }
            if ($payment->coversBlockHalf($block, 2)) {
                $half2 = true;
            }
        }
        if ($full || ($half1 && $half2)) {
            return 0.0;
        }

        $price = self::blockPrice($tariffs, $block) ?? $fallback;
        if ($price === null) {
            return 0.0;
        }

        return ($half1 xor $half2) ? round($price / 2, 2) : $price;
    }

    /** Платёж покрывает блок ЦЕЛИКОМ (тариф full/блок целиком/диапазон), а не половину. */
    private static function paymentIsWholeBlock(Payment $payment): bool
    {
        if ((string) $payment->tariff === 'full') {
            return true;
        }
        $start = (int) $payment->start_block;

        return $start > 0; // диапазон (или одиночный блок) — считается целым покрытием
    }

    /** Базовая цена блока из тарифов курса; null — нет блокового тарифа. */
    private static function blockPrice(iterable $tariffs, int $block): ?float
    {
        foreach ($tariffs as $tariff) {
            if ((int) $tariff->block_number === $block && (float) $tariff->price > 0) {
                return (float) $tariff->price;
            }
            // Диапазонный тариф (start..end без block_number) тоже задаёт цену блока.
            if ((int) $tariff->block_number === 0 && (int) $tariff->start_block > 0
                && $block >= (int) $tariff->start_block && $block <= (int) ($tariff->end_block ?: $tariff->start_block)
                && (float) $tariff->price > 0) {
                return (float) $tariff->price;
            }
        }

        return null;
    }

    /** Фолбэк-цена блока: курсовой тариф / число блоков; null — цен нет вовсе. */
    private static function fallbackBlockPrice(iterable $tariffs, int $blocksTotal, Course $course): ?float
    {
        foreach ($tariffs as $tariff) {
            if ((int) $tariff->block_number === 0 && (int) $tariff->start_block === 0 && (float) $tariff->price > 0) {
                return round((float) $tariff->price / max(1, $blocksTotal), 2);
            }
        }

        return null;
    }

    /** Формат «неоплачено 12 400 ₽ (блоки 5-10)» / «всё оплачено». */
    public static function humanize(array $unpaid): string
    {
        if ($unpaid['amount'] <= 0) {
            return 'всё оплачено';
        }
        $money = number_format($unpaid['amount'], 0, '.', ' ').' ₽'.($unpaid['estimated'] ? ' (оценка)' : '');

        return 'неоплачено '.$money.' (блоки '.$unpaid['blocks_from'].'-'.$unpaid['blocks_to'].')';
    }
}
