<?php

declare(strict_types=1);

namespace App\Services\BankStatements\Concerns;

use App\Services\BankStatements\UnparseableStatementException;

/**
 * Decimal-exact нормализация сумм выписок — та же арифметика, что у
 * импортёра книги (H4188): «70 080,00» / «4 600» / NBSP → «70080.00»,
 * без float на входе парсера.
 */
trait NormalizesMoney
{
    protected function decimal(string $raw): string
    {
        $clean = str_replace(["\u{a0}", "\u{202f}", ' ', ' '], '', trim($raw));

        if (preg_match('/^-?\d+(?:[.,]\d+)?$/', $clean) !== 1) {
            throw new UnparseableStatementException("Сумма «{$raw}» не похожа на число. REFUSE.");
        }

        $normalized = bcadd(str_replace(',', '.', $clean), '0', 2);

        return $normalized;
    }
}
