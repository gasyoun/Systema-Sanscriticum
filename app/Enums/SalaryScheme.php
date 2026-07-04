<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Единый источник правды по схемам оплаты преподавателя (значения колонки
 * `salary_type` на курсе и в pivot co-препода).
 *
 * До этого членство «процентная / фиксированная схема» дублировалось сырыми
 * литералами по нескольким файлам (TeacherSalaryService: инлайн
 * ['percent','percent_per_block'] в двух местах помимо константы; страница
 * TeacherSalaries: свой массив fix_* в isFixedModel). Такие дубликаты не ловятся
 * грепом по имени константы, и добавление новой схемы молча расходилось между
 * страницами. Теперь и членство, и подписи берутся отсюда.
 *
 * ВАЖНО: значения (`value`) — это то, что лежит в БД; менять их нельзя без
 * миграции данных. Схема `percent_per_block` под accrual сходится с `percent`
 * (см. TeacherSalaryService), но остаётся отдельным вариантом для UI/отчётов.
 */
enum SalaryScheme: string
{
    case Percent = 'percent';
    case PercentPerBlock = 'percent_per_block';
    case FixPerStudent = 'fix_per_student';
    case FixTotal = 'fix_total';
    case FixPerBlock = 'fix_per_block';

    /** Процентная схема (доля выручки × %). */
    public function isPercent(): bool
    {
        return in_array($this, [self::Percent, self::PercentPerBlock], true);
    }

    /** Фиксированная схема (за студента / за блок / за курс). */
    public function isFixed(): bool
    {
        return ! $this->isPercent();
    }

    /** @return list<string> значения процентных схем */
    public static function percentValues(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->isPercent()),
        ));
    }

    /** @return list<string> значения фиксированных схем */
    public static function fixedValues(): array
    {
        return array_values(array_map(
            static fn (self $s): string => $s->value,
            array_filter(self::cases(), static fn (self $s): bool => $s->isFixed()),
        ));
    }

    /**
     * Процентная ли схема по сырой строке `salary_type`. Неизвестное/пустое
     * значение → false (как и прежние инлайн-проверки in_array).
     */
    public static function isPercentType(?string $type): bool
    {
        return ($s = self::tryFrom((string) $type)) !== null && $s->isPercent();
    }

    /**
     * Фиксированная ли схема по сырой строке `salary_type`. Неизвестное/пустое
     * значение → false (эквивалент прежнего isFixedModel).
     */
    public static function isFixedType(?string $type): bool
    {
        return ($s = self::tryFrom((string) $type)) !== null && $s->isFixed();
    }

    /** Человекочитаемая короткая подпись схемы. */
    public function label(): string
    {
        return self::labels()[$this->value];
    }

    /**
     * Короткие подписи схем (для селектов и колонок). Порядок сохранён от
     * прежнего TeacherSalaryService::salaryTypeLabels(), чтобы не переставлять
     * пункты в выпадающих списках.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::Percent->value => '% от выручки',
            self::FixPerStudent->value => 'Фикс за студента',
            self::FixTotal->value => 'Фикс за курс',
            self::PercentPerBlock->value => '% за блок',
            self::FixPerBlock->value => 'Фикс за блок',
        ];
    }
}
