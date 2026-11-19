<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Totp;
use PHPUnit\Framework\TestCase;

/**
 * RFC 6238 / 4226 векторы для собственной TOTP-реализации (H6352-r5):
 * секрет — ASCII «12345678901234567890», 6-цифровое усечение.
 */
class TotpTest extends TestCase
{
    private const SECRET_ASCII = '12345678901234567890';

    public function test_rfc_6238_vectors_with_six_digits(): void
    {
        $secret = Totp::base32Encode(self::SECRET_ASCII);

        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret);

        // RFC 6238 (8 цифр) → 6 цифр: 94287082 → 287082, 07081804 → 081804,
        // 14050471 → 050471, 89005924 → 005924.
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
        $this->assertSame('050471', Totp::code($secret, 1111111111));
        $this->assertSame('005924', Totp::code($secret, 1234567890));
    }

    public function test_verify_accepts_one_step_clock_drift(): void
    {
        $secret = Totp::randomSecret();
        $code = Totp::code($secret, 1_700_000_000);

        $this->assertTrue(Totp::verify($secret, $code, 1, 1_700_000_000));
        $this->assertTrue(Totp::verify($secret, $code, 1, 1_700_000_030));
        $this->assertFalse(Totp::verify($secret, $code, 0, 1_700_000_030));
        $this->assertFalse(Totp::verify($secret, $code, 1, 1_700_000_090));
    }

    public function test_verify_rejects_non_numeric_and_wrong_codes(): void
    {
        $secret = Totp::randomSecret();
        $other = Totp::randomSecret();
        $wrong = Totp::code($other, 1_700_000_000);

        $this->assertFalse(Totp::verify($secret, 'abcdef', 1, 1_700_000_000));
        $this->assertFalse(Totp::verify($secret, $wrong, 0, 1_700_000_000));
        $this->assertFalse(Totp::verify($secret, '', 1, 1_700_000_000));
    }

    public function test_base32_round_trip_is_byte_exact(): void
    {
        foreach (['', 'a', 'hello', random_bytes(20)] as $bytes) {
            $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
        }
    }

    public function test_provisioning_uri_carries_secret_and_issuer(): void
    {
        $uri = Totp::provisioningUri('ABC234', 'a@b.c', 'Academy');

        $this->assertStringStartsWith('otpauth://totp/Academy%3Aa%40b.c?', $uri);
        $this->assertStringContainsString('secret=ABC234', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }
}
