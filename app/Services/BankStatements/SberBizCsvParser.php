<?php

declare(strict_types=1);

namespace App\Services\BankStatements;

use App\Services\BankStatements\Concerns\NormalizesMoney;

/**
 * Расширенная выписка Сбербанка (бизнес) — CSV с разделителем «;», часто
 * в cp1251 (H4200). Формат со временем дрейфует, поэтому парсер
 * шапочно-управляемый:
 * колонки ищутся по алиасам («Дата операции»/«Дата», «Дебет»/«Списано»,
 * «Получатель»/«Контрагент», «Назначение платежа»/«Назначение»); не нашли
 * обязательную колонку — UnparseableStatementException со списком реально
 * найденных заголовков. Никогда не угадываем позицию колонки по номеру.
 *
 * Дебет = деньги со счёта наружу (расход); кредитные строки пропускаются
 * (зачисления — не про «Расходы ИП», приход-контур отдельный). Строки
 * «Итого» пропускаются как служебные. Суммы «15 000,50» → «15000.50».
 */
class SberBizCsvParser implements BankStatementParser
{
    use NormalizesMoney;

    private const ALIASES = [
        'date' => ['дата операции', 'дата списания', 'дата'],
        'debit' => ['дебет', 'сумма списания', 'списано', 'расход'],
        'credit' => ['кредит', 'сумма зачисления', 'зачислено', 'приход'],
        'payee' => ['получатель', 'контрагент', 'получатель/плательщик', 'наименование получателя'],
        'purpose' => ['назначение платежа', 'назначение', 'комментарий'],
    ];

    public function parse(string $contents): array
    {
        // Экспорты Сбера бывают в cp1251 (нет валидного UTF-8) — чиним до разбора.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $converted = @iconv('CP1251', 'UTF-8', $contents);

            if ($converted === false) {
                throw new UnparseableStatementException('Файл выписки Сбера не UTF-8 и не cp1251. REFUSE.');
            }

            $contents = $converted;
        }

        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        $lines = array_values(array_filter(
            explode("\n", str_replace("\r\n", "\n", $contents)),
            fn (string $line): bool => trim($line) !== '',
        ));

        if ($lines === []) {
            throw new UnparseableStatementException('Файл выписки Сбера пуст.');
        }

        $delimiter = str_contains($lines[0], ';') ? ';' : (str_contains($lines[0], "\t") ? "\t" : ',');

        $header = array_map(
            fn (string $h): string => mb_strtolower(self::tidy($h)),
            (array) str_getcsv(array_shift($lines), $delimiter),
        );

        $columns = $this->mapColumns($header);

        $rows = [];
        $stats = ['skipped_summary' => 0, 'skipped_credit' => 0];

        foreach ($lines as $line) {
            // НЕ trim($c, "«»"): trim байтовый, съедает последний байт
            // кириллической буквы (0xBB из «л» = байт из «») → битый UTF-8
            // в деньгах. Мультибайтовая обрезка — только через /u-регэксп.
            $cells = array_map(fn (string $c): string => self::tidy($c), (array) str_getcsv($line, $delimiter));

            $value = fn (string $field): ?string => isset($columns[$field]) && $columns[$field] < count($cells)
                ? $cells[$columns[$field]]
                : null;

            $payee = trim((string) $value('payee'));

            // Служебная строка итогов — не расход.
            if (preg_match('/^\s*итого/iu', $payee) === 1) {
                $stats['skipped_summary']++;

                continue;
            }

            $debit = (string) $value('debit');
            $credit = (string) $value('credit');

            if ($debit === '' || bccomp($this->decimal($debit), '0', 2) !== 1) {
                // Зачисление или пустая строка — расходному контуру не интересно.
                if ($credit !== '' && bccomp($this->decimal($credit), '0', 2) === 1) {
                    $stats['skipped_credit']++;
                }

                continue;
            }

            $rows[] = [
                'date' => $this->date((string) $value('date')),
                'amount' => $this->decimal($debit),
                'currency' => 'RUB',
                'payee' => $payee,
                'description' => ($purpose = trim((string) $value('purpose'))) !== '' ? $purpose : null,
            ];
        }

        return ['rows' => $rows, 'stats' => $stats];
    }

    /** Мультибайтово-безопасная обрезка пробелов/кавычек/ёлочек. */
    private static function tidy(string $value): string
    {
        return (string) preg_replace('/^[\s"\x{00AB}\x{00BB}]+|[\s"\x{00AB}\x{00BB}]+$/u', '', $value);
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

        $missing = array_diff(['date', 'debit', 'payee'], array_keys($columns));

        if ($missing !== []) {
            throw new UnparseableStatementException(
                'В выписке Сбера не найдены колонки: '.implode(', ', $missing).'. Найдены: ['.implode(' | ', $header).']. REFUSE.'
            );
        }

        return $columns;
    }

    private function date(string $raw): string
    {
        $raw = trim(explode(' ', trim($raw))[0]);

        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', $raw, $m) === 1) {
            return $m[3].'-'.$m[2].'-'.$m[1];
        }

        throw new UnparseableStatementException("Дата «{$raw}» не ДД.ММ.ГГГГ. REFUSE.");
    }
}
