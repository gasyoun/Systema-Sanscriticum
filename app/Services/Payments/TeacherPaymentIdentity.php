<?php

declare(strict_types=1);

namespace App\Services\Payments;

final class TeacherPaymentIdentity
{
    public static function digest(string $value): string
    {
        $normalized = preg_replace('/\s+/u', '', mb_strtolower(trim($value))) ?? '';
        $key = (string) config('app.key');

        return hash_hmac('sha256', $normalized, $key);
    }

    public static function tail(string $value): string
    {
        $plain = preg_replace('/\D+/', '', $value) ?? '';

        return $plain === '' ? '????' : substr($plain, -4);
    }
}
