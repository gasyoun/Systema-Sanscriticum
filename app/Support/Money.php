<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Неизменяемая денежная величина (рубли), точность — копейка.
 *
 * Внутри хранится целым числом копеек, поэтому сложение/вычитание точны (нет
 * дрейфа float). Это переиспользуемый примитив для денежных вычислений
 * (начисление ЗП, финансовый контур и т.д.).
 *
 * ВАЖНО про округление в начислении ЗП: аудит money-core показал, что текущая
 * дисциплина «копим unrounded float, округляем один раз на границе» —
 * корректна (округление доли раньше времени, наоборот, ВНОСИТ потерю копейки:
 * 10000/3 суммируется в ровно 10000.00, а round(3333.33)×3 = 9999.99).
 * Поэтому TeacherSalaryService НЕ пересчитан на копеечную арифметику Money —
 * он использует только {@see Money::round()} как ЕДИНУЮ политику округления
 * денежной суммы до 2 знаков, чтобы её можно было поменять в одном месте.
 */
final class Money
{
    private function __construct(private readonly int $cents) {}

    /** Из рублёвой суммы (float/int) — округляя до копейки. */
    public static function of(float|int $amount): self
    {
        return new self((int) round((float) $amount * 100));
    }

    public static function ofCents(int $cents): self
    {
        return new self($cents);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Единая политика округления денежной суммы до 2 знаков (копейка).
     * Эквивалент round($amount, 2) — round-half-away-from-zero, как в PHP.
     */
    public static function round(float $amount): float
    {
        return round($amount, 2);
    }

    public function plus(self $other): self
    {
        return new self($this->cents + $other->cents);
    }

    public function minus(self $other): self
    {
        return new self($this->cents - $other->cents);
    }

    public function times(float $factor): self
    {
        return new self((int) round($this->cents * $factor));
    }

    /** Рублёвая сумма (float, 2 знака). */
    public function value(): float
    {
        return $this->cents / 100;
    }

    public function cents(): int
    {
        return $this->cents;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }

    public function isNegative(): bool
    {
        return $this->cents < 0;
    }

    public function isPositive(): bool
    {
        return $this->cents > 0;
    }

    public function equals(self $other): bool
    {
        return $this->cents === $other->cents;
    }

    public function format(int $decimals = 2, string $decimalSep = '.', string $thousandsSep = ' '): string
    {
        return number_format($this->value(), $decimals, $decimalSep, $thousandsSep);
    }
}
