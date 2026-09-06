<?php

declare(strict_types=1);

namespace App\Services\BankStatements;

use App\Services\BankStatements\Concerns\NormalizesMoney;

/**
 * Канонический CSV дебета Точки — формат нашего же сборщика (Uprava
 * tools/pull_tochka_statement.py --debits-csv, H4200):
 *
 *   date,amount,currency,payee,description
 *   2026-08-05,5000.50,RUB,ФНС Налог УСН,авансовый платёж УСН
 *
 * UTF-8, запятая, дата Y-m-d, суммы — положительные decimal-строки (признак
 * «расход» уже применён сборщиком: пишутся только Debit-транзакции). Шапка
 * строгая: любой другой заголовок — UnparseableStatementException.
 */
class TochkaDebitsCsvParser implements BankStatementParser
{
    use NormalizesMoney;

    public const HEADER = ['date', 'amount', 'currency', 'payee', 'description'];

    public function parse(string $contents): array
    {
        // BOM иногда досылает Excel при пересохранении.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $lines = array_values(array_filter(
            explode("\n", str_replace("\r\n", "\n", $contents)),
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            throw new UnparseableStatementException('Файл выписки Точки пуст.');
        }

        $header = str_getcsv(array_shift($lines));
        if ($header !== self::HEADER) {
            throw new UnparseableStatementException(
                'Неизвестная шапка CSV Точки ['.implode(',', $header).'] — ожидалась ['.implode(',', self::HEADER).']. REFUSE.'
            );
        }

        $rows = [];

        foreach ($lines as $i => $line) {
            $cells = str_getcsv($line);

            if (count($cells) !== count(self::HEADER)) {
                throw new UnparseableStatementException(
                    'Строка '.($i + 2).': полей '.count($cells).' вместо '.count(self::HEADER).'. REFUSE.'
                );
            }

            [$date, $amount, $currency, $payee, $description] = $cells;
            $date = trim((string) $date);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw new UnparseableStatementException('Строка '.($i + 2).": дата «{$date}» не Y-m-d. REFUSE.");
            }

            $rows[] = [
                'date' => $date,
                'amount' => $this->decimal((string) $amount),
                'currency' => mb_strtoupper(trim((string) $currency)),
                'payee' => trim((string) $payee),
                'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
            ];
        }

        return ['rows' => $rows, 'stats' => []];
    }
}
