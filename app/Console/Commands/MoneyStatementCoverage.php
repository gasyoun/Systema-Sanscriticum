<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reconciliation\BankStatementCoverage;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H7267 (остаток H5445/H5480): отчёт покрытия выписки по месяцам и дням.
 *
 *   php artisan money:statement-coverage
 *   php artisan money:statement-coverage --from=2026-08-01 --to=2026-10-25
 *   php artisan money:statement-coverage --from=2026-08-01 --to=2026-10-25 --json=/tmp/coverage.json
 *
 * Покрытие читается только из периода, который человек назвал при импорте
 * (`bank_statement_imports.covers_from` / `covers_to`). Даты строк выписки для
 * покрытия не используются; частичный день покрытием не считается. Команда
 * ничего не пишет — ни в деньги, ни в доступ, ни в таблицы сверки, ни во флаги;
 * `--json` пишет только файл, названный человеком.
 */
class MoneyStatementCoverage extends Command
{
    protected $signature = 'money:statement-coverage
        {--from= : первый день окна (YYYY-MM-DD); по умолчанию первый день первого импорта}
        {--to= : последний день окна (YYYY-MM-DD, включительно); по умолчанию вчера}
        {--json= : записать отчёт в файл (путь; - = в stdout)}';

    protected $description = 'H7267: покрытие банковской выписки по месяцам и дням (только чтение)';

    public function handle(BankStatementCoverage $coverage): int
    {
        $tz = (string) config('app.timezone');

        $toRaw = (string) $this->option('to');
        try {
            $to = $toRaw === ''
                ? CarbonImmutable::now($tz)->subDay()->startOfDay()
                : CarbonImmutable::parse($toRaw, $tz)->startOfDay();
        } catch (\Throwable $e) {
            $this->error('не разобрать --to: '.$e->getMessage());

            return self::INVALID;
        }

        $fromRaw = (string) $this->option('from');
        if ($fromRaw === '') {
            $from = $coverage->earliestCoveredFrom();
            if ($from === null) {
                $this->error('импортов выписки нет: задайте окно явно через --from=YYYY-MM-DD --to=YYYY-MM-DD.');

                return self::INVALID;
            }
        } else {
            try {
                $from = CarbonImmutable::parse($fromRaw, $tz)->startOfDay();
            } catch (\Throwable $e) {
                $this->error('не разобрать --from: '.$e->getMessage());

                return self::INVALID;
            }
        }

        if ($to->lessThan($from)) {
            $this->error('окно пустое: --to раньше --from.');

            return self::INVALID;
        }

        $report = $coverage->report($from, $to);

        $this->line('окно: '.$report['window']['from'].'..'.$report['window']['to']
            .' ('.$report['window']['days'].' дн.) · снято '.$report['as_of']);

        if ($report['imports'] === []) {
            $this->warn('импортов выписки нет: покрытие 0 дн. Источник bank_statement остаётся missing.');
        } else {
            $this->table(
                ['импорт', 'провайдер', 'покрывает', 'строк', 'записано', 'дублей', 'сумма, ₽', 'импортирован'],
                array_map(static fn (array $i): array => [
                    '#'.$i['id'],
                    $i['provider'],
                    $i['covers_from'].'..'.$i['covers_to'],
                    $i['rows_total'],
                    $i['rows_imported'],
                    $i['rows_duplicate'],
                    number_format($i['credited_kopecks'] / 100, 2, '.', ' '),
                    (string) $i['imported_at'],
                ], $report['imports']),
            );
        }

        $this->table(
            ['месяц', 'дней', 'покрыто', 'нет', '%', 'покрытые дни', 'непокрытые дни'],
            array_map(static fn (array $m): array => [
                $m['month'],
                $m['days'],
                $m['covered'],
                $m['missing'],
                $m['coverage_pct'],
                $m['covered_ranges'] === [] ? '—' : implode(', ', $m['covered_ranges']),
                $m['missing_ranges'] === [] ? '—' : implode(', ', $m['missing_ranges']),
            ], $report['months']),
        );

        $totals = $report['totals'];
        $this->line(sprintf(
            'итого: %d дн. · покрыто %d · нет %d · %.1f%%',
            $totals['days'],
            $totals['covered'],
            $totals['missing'],
            $totals['coverage_pct'],
        ));
        if ($totals['missing_ranges'] !== []) {
            $this->warn('непокрытые дни: '.implode(', ', $totals['missing_ranges']));
        }

        if (! $report['readable']['enabled']) {
            $this->warn('флаг '.$report['readable']['flag'].' выключен: сверка выписку не читает, '
                .'и покрытые дни останутся missing, пока флаг не включён.');
        }

        $json = $this->option('json');
        if (is_string($json) && $json !== '') {
            $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                $this->error('не сериализовать отчёт в JSON.');

                return self::FAILURE;
            }
            if ($json === '-') {
                $this->line($encoded);
            } elseif (file_put_contents($json, $encoded."\n") === false) {
                $this->error('не записать JSON в '.$json);

                return self::FAILURE;
            } else {
                $this->line('JSON: '.$json);
            }
        }

        return self::SUCCESS;
    }
}
