<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * H5443 (P1): точная копеечная арифметика денежного ядра — только целые.
 *
 * Разбор десятичной строки идёт по цифрам, без float: «1234.5» → 123450.
 * Float на входе запрещён типом — именно float-дрейф ядро исключает.
 */
final class Kopecks
{
    /** Десятичная строка рублей (как её отдаёт decimal-колонка) → копейки. */
    public static function fromDecimal(string|int $rubles): int
    {
        if (is_int($rubles)) {
            return $rubles * 100;
        }

        $s = trim($rubles);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2})0*)?$/', $s, $m)) {
            throw new InvalidArgumentException("Не денежная сумма в рублях с точностью до копейки: «{$rubles}»");
        }

        $kopecks = ((int) $m[2]) * 100 + (int) str_pad($m[3] ?? '', 2, '0');

        return $m[1] === '-' ? -$kopecks : $kopecks;
    }

    /** Копейки → десятичная строка рублей «1234.50» (для совместимых отчётов). */
    public static function toDecimal(int $kopecks): string
    {
        $sign = $kopecks < 0 ? '-' : '';
        $abs = abs($kopecks);

        return $sign.intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Делит сумму на $parts целых долей без потери копейки: остаток деления
     * достаётся последней доле, сумма долей всегда равна исходной.
     *
     * @return list<int>
     */
    public static function split(int $total, int $parts): array
    {
        if ($parts < 1) {
            throw new InvalidArgumentException('Число долей должно быть >= 1');
        }

        $base = intdiv($total, $parts);
        $shares = array_fill(0, $parts, $base);
        $shares[$parts - 1] += $total - $base * $parts;

        return $shares;
    }
}
