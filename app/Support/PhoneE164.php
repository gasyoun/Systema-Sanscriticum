<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Телефон в E.164 («+» и только цифры) — формат, которого требует Digital Kassa
 * в notify.phone (иначе чек отклоняется). users.phone хранится как ввели, поэтому
 * российские формы (8XXXXXXXXXX, 10 цифр) приводим к +7; неоднозначное → null.
 */
final class PhoneE164
{
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $hasPlus = str_starts_with(ltrim($raw), '+');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        if ($hasPlus) {
            return strlen($digits) >= 8 && strlen($digits) <= 15 ? '+'.$digits : null;
        }

        if (strlen($digits) === 11 && ($digits[0] === '8' || $digits[0] === '7')) {
            return '+7'.substr($digits, 1);
        }

        if (strlen($digits) === 10 && $digits[0] === '9') {
            return '+7'.$digits;
        }

        return null;
    }
}
