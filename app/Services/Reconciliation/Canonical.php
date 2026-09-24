<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

/**
 * H5445 (P3): канонический JSON для контрольных сумм — ключи отсортированы
 * рекурсивно, списки сохраняют порядок, float запрещён (деньги — копейки).
 * Одинаковый вход всегда даёт одинаковую строку и одинаковый sha256.
 */
final class Canonical
{
    public static function json(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public static function sha256(mixed $value): string
    {
        return hash('sha256', self::json($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_float($value)) {
            throw new ReconInvariantViolation('recon: float in a checksummed value — use integer kopecks or a decimal string');
        }
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::normalize(...), $value);
    }
}
