<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Единый источник правды по статьям операционных расходов (opex) — та часть
 * ОПиУ, которой у LMS раньше не было вовсе. Маркетинг (Ad*Spend) и ЗП
 * (TeacherPayout) моделируются отдельными сущностями и сюда НЕ входят — здесь
 * только «прочие» операционные расходы: эквайринг, хостинг, подрядчики, налоги.
 *
 * ВАЖНО: `value` — это то, что лежит в колонке `expenses.category`; менять
 * значения нельзя без миграции данных. Список статей согласован с MG
 * (05-07-2026) как chart of accounts под «Нескучные финансы».
 *
 * Соответствие блокам ОПиУ (НФ): эквайринг — переменный расход (растёт с
 * выручкой), остальные — административные. Блок определяет, в какую строку
 * P&L попадёт статья (см. FinanceCockpitReport::opiu).
 */
enum ExpenseCategory: string
{
    case Acquiring = 'acquiring';
    case Hosting = 'hosting';
    case Contractors = 'contractors';
    case TaxesBankOther = 'taxes_bank_other';

    /** Человеческая подпись статьи. */
    public function label(): string
    {
        return match ($this) {
            self::Acquiring => 'Эквайринг и комиссии',
            self::Hosting => 'Хостинг, платформа, SaaS',
            self::Contractors => 'Подрядчики и фриланс',
            self::TaxesBankOther => 'Налоги, банк, прочее',
        };
    }

    /**
     * Переменный расход (масштабируется с выручкой) → блок «Переменные» ОПиУ.
     * Иначе — административный расход (блок «Административные»).
     */
    public function isVariable(): bool
    {
        return $this === self::Acquiring;
    }

    /** Подпись блока ОПиУ, куда падает статья. */
    public function blockLabel(): string
    {
        return $this->isVariable() ? 'Переменные' : 'Административные';
    }

    /**
     * @return array<string,string> [value => label] для Filament Select/фильтров
     */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
