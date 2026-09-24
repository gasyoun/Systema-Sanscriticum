<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reconciliation\BankStatementImporter;
use App\Services\Reconciliation\StatementFormatError;
use App\Support\Kopecks;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H5480 (P3b): импорт ЗАЧИСЛЕНИЙ банковской выписки (CSV, который бухгалтер и
 * так выгружает из Точки) — источник `bank_statement` для H5445.
 *
 *   php artisan money:import-bank-credits /var/www/statements/Tochka_09.csv \
 *       --from=2026-09-01 --to=2026-09-30
 *   php artisan money:import-bank-credits <path> --dry-run   # ничего не пишет
 *
 * Идемпотентно: тот же файл — ноль новых строк; пересекающиеся выписки
 * дедуплицируются по row_hash. Период задаётся ЯВНО (--from/--to = период
 * выгрузки); без него он выводится по датам строк и это лишь НИЖНЯЯ оценка
 * покрытия — день без зачислений в такую оценку не попадёт и останется
 * missing. Веб-загрузки в этой единице нет: файл читается с хоста.
 */
class MoneyImportBankCredits extends Command
{
    protected $signature = 'money:import-bank-credits
        {path : путь к CSV выписки на хосте}
        {--from= : первый день периода выгрузки Y-m-d}
        {--to= : последний день периода выгрузки Y-m-d}
        {--dry-run : разобрать и показать итоги, ничего не записывая}';

    protected $description = 'H5480 (P3b): импорт зачислений банковской выписки для сверки (append-only, идемпотентно)';

    public function handle(BankStatementImporter $importer): int
    {
        $path = (string) $this->argument('path');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Файл не найден или не читается: {$path}");

            return self::INVALID;
        }

        $from = $this->day('from');
        $to = $this->day('to');
        if ($from === false || $to === false) {
            $this->error('--from/--to ожидают Y-m-d');

            return self::INVALID;
        }
        if (($from === null) !== ($to === null)) {
            $this->error('--from и --to задаются только вместе (период выгрузки целиком).');

            return self::INVALID;
        }

        try {
            $r = $importer->import($path, $from, $to, ! $this->option('dry-run'));
        } catch (StatementFormatError $e) {
            $this->error('Выписка не разобрана: '.$e->getMessage());

            return self::FAILURE;
        }

        $s = $r['statement'];
        $this->line(($this->option('dry-run') ? 'СУХОЙ ПРОГОН: ' : '').
            "выписка {$s->file_name}: период {$s->covers_from->toDateString()}…{$s->covers_to->toDateString()} ({$s->period_source})");
        if ($r['already_imported']) {
            $this->comment('Этот файл уже импортирован (тот же sha256) — новых строк 0.');
        }
        $this->line("Разобрано зачислений: {$r['parsed']}, записано: {$r['inserted']}, дублей: {$r['duplicates']}, не разобрано строк: {$r['skipped']}");
        $this->line('Сумма зачислений: '.Kopecks::toDecimal($r['kopecks']).' ₽');
        foreach ($r['by_kind'] as $kind => $agg) {
            $this->line(sprintf('  %-26s %5d стр.  %s ₽', $kind, $agg['rows'], Kopecks::toDecimal($agg['kopecks'])));
        }
        if ($r['skipped'] > 0) {
            $this->warn("{$r['skipped']} строк(и) зачислений не разобраны (дата/сумма) — проверьте выписку, они НЕ попали в покрытие.");
        }
        if ($s->period_source === 'derived' && ! $this->option('dry-run')) {
            $this->warn('Период выведен по датам строк — это нижняя оценка покрытия. Для честного complete передайте --from/--to.');
        }

        return self::SUCCESS;
    }

    private function day(string $option): CarbonImmutable|null|false
    {
        $raw = $this->option($option);
        if ($raw === null || $raw === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', (string) $raw, (string) config('app.timezone')) ?: false;
    }
}
