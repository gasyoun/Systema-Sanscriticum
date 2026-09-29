<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Kopecks;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** H5443 (P1): копеечная арифметика ядра — только целые, без float. */
class KopecksTest extends TestCase
{
    /** @return array<string, array{0: string|int, 1: int}> */
    public static function decimals(): array
    {
        return [
            'integer rubles' => ['1234', 123400],
            'one decimal' => ['1234.5', 123450],
            'two decimals' => ['1234.56', 123456],
            'db decimal(10,2)' => ['0.10', 10],
            'trailing zeros' => ['7.500', 750],
            'negative' => ['-400.00', -40000],
            'padded' => [' 12.30 ', 1230],
            'int input' => [15, 1500],
            // 0.1 + 0.2 ломает float; по цифрам — ровно.
            'float trap' => ['0.30', 30],
        ];
    }

    #[DataProvider('decimals')]
    public function test_from_decimal_parses_digits_without_float(string|int $in, int $expected): void
    {
        $this->assertSame($expected, Kopecks::fromDecimal($in));
    }

    /** @return array<string, array{0: string}> */
    public static function garbage(): array
    {
        return [
            'sub-kopeck' => ['1.005'],
            'comma' => ['1,50'],
            'empty' => [''],
            'exponent' => ['1e3'],
        ];
    }

    #[DataProvider('garbage')]
    public function test_from_decimal_rejects_non_money(string $in): void
    {
        $this->expectException(InvalidArgumentException::class);
        Kopecks::fromDecimal($in);
    }

    public function test_to_decimal_round_trips(): void
    {
        foreach ([0, 5, 99, 100, 123450, -40000, -5] as $k) {
            $this->assertSame($k, Kopecks::fromDecimal(Kopecks::toDecimal($k)));
        }
        $this->assertSame('1234.50', Kopecks::toDecimal(123450));
        $this->assertSame('-0.05', Kopecks::toDecimal(-5));
    }

    public function test_split_never_loses_a_kopeck(): void
    {
        $this->assertSame([333333, 333333, 333334], Kopecks::split(1_000_000, 3));
        $this->assertSame([0, 0, 1], Kopecks::split(1, 3));
        mt_srand(5443);
        for ($i = 0; $i < 500; $i++) {
            $total = mt_rand(0, 10_000_000);
            $parts = mt_rand(1, 12);
            $shares = Kopecks::split($total, $parts);
            $this->assertCount($parts, $shares);
            $this->assertSame($total, array_sum($shares));
        }
    }

    public function test_split_needs_at_least_one_part(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Kopecks::split(100, 0);
    }
}
