<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H5086: нейтрализация spreadsheet-формул в выгрузках.
 *
 * Гостевые строки (лиды, ответы анкет, имена студентов) попадают в CSV/XLSX,
 * которые персонал открывает в Excel/LibreOffice (BOM «для Excel»). Строка,
 * начинающаяся с =, +, -, @ (а также с табуляции/CR — продолжение формулы
 * на следующей ячейке), исполняется как формула (HYPERLINK/WEBSERVICE/DDE
 * exfil). Все экспортёры прогоняют строки через этот гард: опасный префикс
 * нейтрализуется ведущей одинарной кавычкой (стандартная мера OWASP), число
 * и null не трогаются.
 */
final class FormulaGuard
{
    private const DANGEROUS_PREFIX = '/^[=+\-@\t\r]/';

    /**
     * Нейтрализовать одну ячейку: строки с опасным первым символом получают
     * ведущий апостроф, всё остальное проходит без изменений.
     */
    public static function cell(string|int|float|null $value): string|int|float|null
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return preg_match(self::DANGEROUS_PREFIX, $value) === 1
            ? "'".$value
            : $value;
    }

    /**
     * Прогнать всю строку таблицы перед fputcsv()/Excel-сериализацией.
     *
     * @param  array<int, string|int|float|null>  $row
     * @return array<int, string|int|float|null>
     */
    public static function row(array $row): array
    {
        return array_map([self::class, 'cell'], $row);
    }
}
