<?php

declare(strict_types=1);

namespace App\Support;

/**
 * RFC 6238 TOTP (SHA-1, 6 цифр, шаг 30 с) и RFC 4648 base32 — без внешних
 * зависимостей: H6352-r5 (рулинг 7 грилла 10-10) требует 2FA всем админ-ролям,
 * а новый composer-пакет на money/access-контуре — лишняя поверхность.
 *
 * Вектор RFC 6238 (секрет ASCII «12345678901234567890», 8 цифр) проверяется
 * тестом Tests\Unit\TotpTest: T=59 → 94287082, то есть 6-цифровое усечение
 * 287082. Приложение показывает 6 цифр (DIGITS).
 */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    /** Алфавит RFC 4648 без padding. */
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Случайный секрет (20 байт = 160 бит, как рекомендует RFC 4226) в base32. */
    public static function randomSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $binary = pack('N2', ($counter >> 32) & 0xFFFFFFFF, $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binary, self::base32Decode($secret), true);

        $offset = ord($hash[19]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** @param int $window допуск в шагах по 30 с в обе стороны (часы расходятся) */
    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (preg_match('/^\d{'.self::DIGITS.'}$/', $code) !== 1) {
            return false;
        }

        $now = $timestamp ?? time();

        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $now + $offset * self::PERIOD), $code)) {
                return true;
            }
        }

        return false;
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $encoded): string
    {
        $encoded = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $encoded) ?? '');
        $bits = '';

        foreach (str_split($encoded) as $char) {
            $index = strpos(self::ALPHABET, $char);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }

    /** otpauth://-ссылка для QR/ручного ввода в приложении-аутентификаторе. */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$account);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secret,
            rawurlencode($issuer),
            self::DIGITS,
            self::PERIOD,
        );
    }
}
