<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Reconciliation\BankStatementImporter;
use App\Services\Reconciliation\StatementFormatError;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * H5480: импорт зачислений выписки Точки, которую бухгалтер выгрузил руками.
 *
 * Период покрытия обязателен и задаётся человеком: только он знает, что выбрал
 * при выгрузке. Из строк его выводить нельзя — день без зачислений тогда
 * выглядел бы непокрытым. Сухой прогон (--dry-run) ничего не пишет.
 *
 * Денег команда не создаёт и не меняет, доступа не выдаёт: пишет только
 * bank_statement_imports / bank_statement_credits (append-only).
 */
class ImportBankStatementCredits extends Command
{
    protected $signature = 'money:import-statement-credits
        {path : путь к CSV выписки на хосте}
        {--from= : первый покрытый день (YYYY-MM-DD), обязателен}
        {--to= : последний покрытый день (YYYY-MM-DD, включительно), обязателен}
        {--dry-run : разобрать и показать итоги, не записывая}';

    protected $description = 'H5480: импортировать зачисления банковской выписки (источник bank_statement для money:reconcile-daily)';

    public function handle(BankStatementImporter $importer): int
    {
        $path = (string) $this->argument('path');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');

        if ($from === '' || $to === '') {
            $this->error('--from и --to обязательны: покрытый период задаёт человек, а не строки файла.');

            return self::INVALID;
        }

        $tz = (string) config('app.timezone');
        try {
            $coversFrom = CarbonImmutable::parse($from, $tz)->startOfDay();
            $coversTo = CarbonImmutable::parse($to, $tz)->endOfDay();
        } catch (\Throwable $e) {
            $this->error('не разобрать период: '.$e->getMessage());

            return self::INVALID;
        }

        try {
            $r = $importer->importFile($path, $coversFrom, $coversTo, (bool) $this->option('dry-run'));
        } catch (StatementFormatError $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('исход: '.$r['outcome']);
        $this->line('период покрытия: '.$coversFrom->toDateTimeString().' .. '.$coversTo->toDateTimeString());
        $this->line(sprintf(
            'зачислений в файле: %d · записано: %d · уже было: %d · не разобрано: %d · сумма записанного: %s ₽',
            $r['total'], $r['imported'], $r['duplicate'], $r['skipped'], number_format($r['kopecks'] / 100, 2, '.', ' '),
        ));
        foreach ($r['by_kind'] as $kind => $agg) {
            $this->line(sprintf('  %-26s %5d строк · %s ₽', $kind, $agg['rows'], number_format($agg['kopecks'] / 100, 2, '.', ' ')));
        }
        if ($r['skipped'] > 0) {
            $this->warn($r['skipped'].' строк(и) с неразборчивой датой/суммой пропущено — это не «их не было», проверьте файл.');
        }
        if ($r['import'] !== null) {
            $this->line('импорт #'.$r['import']->id.' · sha256 файла '.$r['import']->file_sha256);
        }

        return self::SUCCESS;
    }
}
