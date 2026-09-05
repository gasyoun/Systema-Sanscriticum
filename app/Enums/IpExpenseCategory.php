<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Статьи расхода контура «Расходы ИП» (H4188) — категория, в которой книга
 * «Расходы по ИП» группировала траты: налоги, банк, эквайринг, подрядчики,
 * зп, прочее. Отдельный от opex (ExpenseCategory) chart of accounts: контур
 * ИП-книги зеркалит легаси-расходы CRM (двойной счёт Oct'25–May'26, см.
 * AUDIT_BOOKKEEPING_MISERABLE_MAP §3), поэтому слипать их в один enum нельзя
 * до решения @DECIDE о сверке.
 *
 * Импортируемые из книги строки получают эвристическую категорию по ключевые
 * словам; оператор пере-категоризует в админке — аудит правок ведётся.
 */
enum IpExpenseCategory: string
{
    case Taxes = 'taxes';

    case Bank = 'bank';

    case Acquiring = 'acquiring';

    case Contractors = 'contractors';

    case Salaries = 'salaries';

    case Other = 'other';

    /** Человеческая подпись статьи. */
    public function label(): string
    {
        return match ($this) {
            self::Taxes => 'Налоги',
            self::Bank => 'Банк',
            self::Acquiring => 'Эквайринг',
            self::Contractors => 'Подрядчики',
            self::Salaries => 'ЗП',
            self::Other => 'Прочее',
        };
    }

    /**
     * Эвристика категории по тексту строки книги (наименование + примечание
     * + счёт). Консервативная: не распознали — «Прочее», оператор поправит.
     */
    public static function guess(string ...$texts): self
    {
        $haystack = mb_strtolower(implode(' ', array_filter($texts)));

        if (preg_match('/налог|усн|страхован|пенсион|казначейство|фсс|взнос/', $haystack) === 1) {
            return self::Taxes;
        }

        if (preg_match('/эквайринг|commission|комиссия за прием/', $haystack) === 1) {
            return self::Acquiring;
        }

        if (preg_match('/банк|банковск|обслуживание счета|тариф.*счет|тинькофф|точка|сбер(?!\s*карта)/u', $haystack) === 1) {
            return self::Bank;
        }

        if (preg_match('/зп|зарплат|аванс|выплата преподав|оклад/u', $haystack) === 1) {
            return self::Salaries;
        }

        if (preg_match('/подрядчик|фриланс|договор (на|подряд)|разработк|дизайн|хостинг|домен|сервис|подписк/i', $haystack) === 1) {
            return self::Contractors;
        }

        return self::Other;
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
