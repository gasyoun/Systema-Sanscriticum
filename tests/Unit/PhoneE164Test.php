<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PhoneE164;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneE164Test extends TestCase
{
    public static function cases(): array
    {
        return [
            'ru 8-prefix formatted' => ['8 (916) 123-45-67', '+79161234567'],
            'ru 7 without plus' => ['79161234567', '+79161234567'],
            'ru 10 digits' => ['9161234567', '+79161234567'],
            'plus kept' => ['+7 916 123-45-67', '+79161234567'],
            'foreign with plus' => ['+49 30 1234567', '+49301234567'],
            'ambiguous short' => ['12345', null],
            'ambiguous foreign without plus' => ['4930123456789', null],
            'empty' => ['', null],
            'null' => [null, null],
        ];
    }

    #[DataProvider('cases')]
    public function test_normalize(?string $raw, ?string $expected): void
    {
        $this->assertSame($expected, PhoneE164::normalize($raw));
    }
}
