<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H5086 — защита CSV/xlsx-выгрузок от formula injection (находка H5046
 * csv-export-formula-injection, remediation).
 *
 * Excel/Calc/LibreOffice интерпретируют ячейку как формулу, если её текст
 * начинается с =, +, - или @; ведущий TAB/CR приводит к той же трактовке при
 * вставке/импорте. Пользовательский ввод (имя лида, ответ анкеты, UTM,
 * user agent, имя студента) попадает в выгрузки как есть — crafted заявка
 * могла исполниться формулой у сотрудника, открывшего файл.
 *
 * Лечение по OWASP: такой ячейке ставится ведущий одинарный апостроф —
 * Excel считает содержимое текстом и сам апостроф не показывает. Данные не
 * режутся (меняется только префикс), числа и NULL проходят как есть — тип
 * ячейки в xlsx сохраняется.
 */
final class CsvFormulaGuard
{
    /** Символы, с которых формула начинается в Excel/Calc (+ TAB/CR). */
    private const DANGEROUS_FIRST_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Одна ячейка таблицы: строки нейтрализуются, прочие типы
     * (int/float/bool/null) проходят без изменений.
     */
    public static function cell(mixed $value): mixed
    {
        return is_string($value) ? self::text($value) : $value;
    }

    /** Строковая ячейка с нейтрализацией ведущего = + - @ TAB CR. */
    public static function text(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        // Все маркеры однобайтовые ASCII, поэтому индекс [0] безопасен
        // и для многобайтовых (кириллица и т.п.) строк.
        return in_array($value[0], self::DANGEROUS_FIRST_CHARS, true)
            ? '\''.$value
            : $value;
    }

    /** Строка таблицы целиком (путь fputcsv). */
    public static function row(array $cells): array
    {
        return array_map(self::cell(...), $cells);
    }
}
