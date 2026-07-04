<?php

namespace Tests\Unit;

use App\Support\Money;
use Tests\TestCase;

class MoneyTest extends TestCase
{
    /** @test */
    public function round_is_identical_to_php_round_two_dp(): void
    {
        // Money::round — единая политика; должна совпадать с round($x, 2) везде,
        // включая копеечную границу (half-away, как PHP round()).
        foreach ([0.0, 1.0, 3333.333333, 9999.995, 0.005, 1000.0 / 3, -1000.0 / 3, 65789.955] as $x) {
            $this->assertSame(round($x, 2), Money::round($x));
        }
    }

    /** @test */
    public function of_and_value_round_trip_at_kopeck_precision(): void
    {
        $this->assertSame(1234.56, Money::of(1234.56)->value());
        $this->assertSame(1234.99, Money::of(1234.994)->value()); // sub-kopeck dropped
        $this->assertSame(0.0, Money::zero()->value());
        $this->assertSame(5000, Money::of(50)->cents());
    }

    /** @test */
    public function arithmetic_is_exact_in_cents(): void
    {
        // Классическая ловушка float: 0.1 + 0.2 !== 0.3. В копейках — точно.
        $this->assertTrue(Money::of(0.1)->plus(Money::of(0.2))->equals(Money::of(0.3)));
        $this->assertSame(10.0, Money::of(2.5)->times(4)->value());
        $this->assertSame(2.5, Money::of(10)->minus(Money::of(7.5))->value());
    }

    /** @test */
    public function sign_and_zero_predicates(): void
    {
        $this->assertTrue(Money::of(-0.01)->isNegative());
        $this->assertFalse(Money::of(0)->isNegative());
        $this->assertTrue(Money::zero()->isZero());
        $this->assertTrue(Money::of(0.01)->isPositive());
    }

    /** @test */
    public function format_uses_space_thousands_and_dot_decimal_by_default(): void
    {
        $this->assertSame('1 234.56', Money::of(1234.56)->format());
        $this->assertSame('1 235', Money::of(1234.56)->format(0));
    }
}
