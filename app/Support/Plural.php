<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Русская плюрализация по числительному.
 *
 * Пример: Plural::ru(5, 'день', 'дня', 'дней') === 'дней'.
 */
final class Plural
{
    /**
     * @param  string  $one  форма для 1, 21, 31… (1 день)
     * @param  string  $few  форма для 2–4, 22–24… (2 дня)
     * @param  string  $many  форма для 0, 5–20, 11–14… (5 дней)
     */
    public static function ru(int $n, string $one, string $few, string $many): string
    {
        $n = abs($n) % 100;
        $n1 = $n % 10;

        if ($n > 10 && $n < 20) {
            return $many;
        }
        if ($n1 > 1 && $n1 < 5) {
            return $few;
        }
        if ($n1 === 1) {
            return $one;
        }

        return $many;
    }
}
