<?php

declare(strict_types=1);

namespace App\Services\BankStatements;

use App\Services\BankStatements\Concerns\NormalizesMoney;

/**
 * Экспорт истории транзакций PayPal (Custom CSV / Activity export, шапка EN
 * — в ранбуке просим выгрузку с английскими заголовками, H4200).
 *
 * Расход = Completed-строка с отрицательным Gross (деньги наружу) КРОМЕ
 * внутренних перемещений между своими счетами («Transfer to Bank» и т.п.) —
 * перевод PayPal→банк не расход бизнеса, а перекладка валюты (иначе двойной
 * счёт с приходным контуром). Зачисления (положительный Gross) пропускаются —
 * это доход, не «Расходы ИП».
 *
 * Сумма остаётся В ВАЛЮТЕ транзакции (EUR/USD/...): колонка currency несёт
 * её честно, fx_note строит команда импорта — курс не выдумываем.
 *
 * Даты PayPal бывают M/D/YYYY, бывают D/M/YYYY — надёжно различимы только
 * когда одна из частей > 12; неоднозначные читаются как M/D (формат США по
 * умолчанию) и считаются в stats['ambiguous_dates'] — dry-run показывает
 * счётчик, оператор сверяет с выпиской.
 */
class PayPalCsvParser implements BankStatementParser
{
    use NormalizesMoney;

    private const ALIASES = [
        'date' => ['date', 'дата'],
        'name' => ['name', 'имя'],
        'type' => ['type', 'тип'],
        'status' => ['status', 'статус'],
        'currency' => ['currency', 'валюта'],
        'gross' => ['gross', 'сумма'],
        'item' => ['item title', 'название товара'],
    ];

    private const STATUSES_COMPLETED = ['completed', 'завершено'];

    private const INTERNAL_TYPES = '/transfer to bank|bank transfer|перевод на (банковск|карту)/iu';

    public function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $lines = array_values(array_filter(
            explode("\n", str_replace("\r\n", "\n", $contents)),
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            throw new UnparseableStatementException('Файл выписки PayPal пуст.');
        }

        $header = array_map(
            fn (string $h): string => mb_strtolower(trim($h, " \t\"")),
            (array) str_getcsv(array_shift($lines)),
        );

        $columns = $this->mapColumns($header);

        $rows = [];
        $stats = ['skipped_income' => 0, 'skipped_status' => 0, 'skipped_internal' => 0, 'ambiguous_dates' => 0];

        foreach ($lines as $line) {
            $cells = array_map(fn (string $c): string => trim($c, " \t\""), (array) str_getcsv($line));

            $value = fn (string $field): ?string => isset($columns[$field]) && $columns[$field] < count($cells)
                ? $cells[$columns[$field]]
                : null;

            if (mb_strtolower((string) $value('status')) !== '' && ! in_array(mb_strtolower((string) $value('status')), self::STATUSES_COMPLETED, true)) {
                $stats['skipped_status']++;

                continue;
            }

            $gross = $this->decimal((string) $value('gross'));

            // Отрицательный Gross = деньги ушли со счёта PayPal.
            if (bccomp($gross, '0', 2) !== -1) {
                $stats['skipped_income']++;

                continue;
            }

            $type = (string) $value('type');

            if (preg_match(self::INTERNAL_TYPES, $type) === 1) {
                $stats['skipped_internal']++;

                continue;
            }

            $date = $this->date((string) $value('date'), $stats);

            $rows[] = [
                'date' => $date,
                'amount' => bcmul($gross, '-1', 2),
                'currency' => mb_strtoupper((string) $value('currency')),
                'payee' => trim((string) $value('name')),
                'description' => ($item = trim((string) $value('item'))) !== '' ? $item : ($type !== '' ? $type : null),
            ];
        }

        return ['rows' => $rows, 'stats' => $stats];
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function mapColumns(array $header): array
    {
        $columns = [];

        foreach (self::ALIASES as $field => $aliases) {
            foreach ($header as $i => $title) {
                if (in_array($title, $aliases, true)) {
                    $columns[$field] = $i;

                    break;
                }
            }
        }

        $missing = array_diff(['date', 'name', 'status', 'currency', 'gross'], array_keys($columns));

        if ($missing !== []) {
            throw new UnparseableStatementException(
                'В выписке PayPal не найдены колонки: '.implode(', ', $missing).'. Найдены: ['.implode(' | ', $header).']. REFUSE.'
            );
        }

        return $columns;
    }

    /**
     * @param  array<string, int>  $stats
     */
    private function date(string $raw, array &$stats): string
    {
        $raw = trim(explode(' ', trim($raw))[0]);

        $parts = preg_split('#[/.]#', $raw) ?: [];

        if (count($parts) !== 3 || strlen((string) $parts[2]) !== 4) {
            throw new UnparseableStatementException("Дата PayPal «{$raw}» не M/D/YYYY. REFUSE.");
        }

        [$a, $b, $year] = $parts;
        $a = (int) $a;
        $b = (int) $b;

        if ($a > 12 && $b <= 12) {
            $month = $b;
            $day = $a;
        } elseif ($b > 12 && $a <= 12) {
            $month = $a;
            $day = $b;
        } else {
            // Обе части ≤ 12: читаем как M/D (формат США) и считаем счётчик.
            $month = $a;
            $day = $b;
            $stats['ambiguous_dates']++;
        }

        if (checkdate($month, $day, (int) $year) !== true) {
            throw new UnparseableStatementException("Дата PayPal «{$raw}» не существует. REFUSE.");
        }

        return sprintf('%04d-%02d-%02d', (int) $year, $month, $day);
    }
}
