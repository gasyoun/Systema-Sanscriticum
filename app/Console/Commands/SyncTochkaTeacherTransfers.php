<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payments\TochkaApiCreditImporter;
use App\Services\Payments\TochkaStatementReader;
use App\Services\Payments\TochkaTeacherTransferImporter;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

final class SyncTochkaTeacherTransfers extends Command
{
    protected $signature = 'money:sync-tochka-teacher-transfers
        {--from= : first covered date, YYYY-MM-DD}
        {--to= : last covered date, YYYY-MM-DD}
        {--file= : optional private JSON export instead of the API}
        {--apply : persist immutable evidence; default is dry-run}';

    protected $description = 'Import Booked Tochka debits as teacher-transfer evidence (never creates payouts)';

    public function handle(TochkaStatementReader $reader, TochkaTeacherTransferImporter $importer, TochkaApiCreditImporter $credits): int
    {
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) !== 1 || preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) !== 1 || $from > $to) {
            $this->error('--from and --to are required valid dates');

            return self::INVALID;
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! (bool) config('features.money_tochka_teacher_transfers', false)) {
            $this->error('MONEY_TOCHKA_TEACHER_TRANSFERS is off; refusing --apply');

            return self::FAILURE;
        }
        if ($apply && ! (bool) config('features.money_bank_statement_credits', false)) {
            $this->error('MONEY_BANK_STATEMENT_CREDITS is off; refusing incomplete --apply');

            return self::FAILURE;
        }

        try {
            $file = (string) $this->option('file');
            $payload = $file === '' ? $reader->read($from, $to) : json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload)) {
                throw new RuntimeException('statement payload is not an object');
            }
            $result = $importer->importPayload($payload, $apply);
            $creditResult = $credits->importPayload(
                $payload,
                CarbonImmutable::parse($from),
                CarbonImmutable::parse($to),
                $apply,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($result as $key => $value) {
            $this->line("teacher_debits.{$key}: {$value}");
        }
        foreach ($creditResult as $key => $value) {
            $this->line("student_credits.{$key}: {$value}");
        }
        if ($result['conflict'] > 0 || $result['skipped'] > 0 || $creditResult['skipped'] > 0) {
            $this->warn('Evidence is incomplete; affected payroll lines must remain held.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
