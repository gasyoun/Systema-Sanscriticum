<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Payroll\PayrollReadinessService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

final class PayrollReadinessCommand extends Command
{
    protected $signature = 'payroll:readiness
        {--on= : Live cutoff date/time; defaults to payroll_readiness.target_date}
        {--export= : Private JSON path; "auto" writes under storage/app/private/payroll}
        {--expect-fingerprint= : Fail if live recomputation no longer matches the approved export}';

    protected $description = 'Read-only teacher payroll census, evidence gate, private export, and stale-fingerprint check';

    public function handle(PayrollReadinessService $service): int
    {
        $cutoff = filled($this->option('on'))
            ? Carbon::parse((string) $this->option('on'))
            : Carbon::parse((string) config('payroll_readiness.target_date'));
        $report = $service->build($cutoff);

        if ($report['read_only']['moved']) {
            $this->error('READ-ONLY VIOLATION: a money-table fingerprint moved during the census');

            return self::FAILURE;
        }

        $expected = trim((string) ($this->option('expect-fingerprint') ?? ''));
        if ($expected !== '' && ! hash_equals($expected, (string) $report['fingerprint'])) {
            $this->error('STALE APPROVAL: live fingerprint differs; regenerate and re-approve this row/package');

            return self::FAILURE;
        }

        $export = $this->option('export');
        if ($export !== null && $export !== '') {
            $path = $export === 'auto'
                ? storage_path('app/private/payroll/readiness-'.$cutoff->format('Ymd-His').'.json')
                : (string) $export;
            if (str_contains(str_replace('\\', '/', $path), '/public/')) {
                $this->error('Private payroll export cannot be written under a public directory');

                return self::FAILURE;
            }
            File::ensureDirectoryExists(dirname($path), 0700, true);
            File::put($path, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $this->info('Private export: '.$path);
        }

        $this->table(
            ['cutoff', 'teachers', 'payable', 'held', 'fingerprint'],
            [[
                $report['cutoff'],
                $report['actual_teacher_count'],
                $report['totals']['by_disposition']['payable'] ?? 0,
                $report['totals']['by_disposition']['held'] ?? 0,
                $report['fingerprint'],
            ]],
        );

        $this->table(
            ['ID', 'Teacher', 'Disposition', 'Due', 'Current RUB', 'EUR', 'Basis', 'Channel', 'Last paid', 'Last amount', 'Days', 'Holds'],
            collect($report['teachers'])->map(fn (array $row): array => [
                $row['teacher_id'],
                $row['name'],
                $row['disposition'],
                $row['due_on'],
                $row['payable_rub'] === null ? 'INCOMPLETE' : number_format((float) $row['payable_rub'], 2, '.', ''),
                $row['payable_eur'] === null ? '' : number_format((float) $row['payable_eur'], 2, '.', ''),
                $row['amount_basis'],
                $row['channel'],
                $row['last_actual_transfer']['date'] ?? 'never',
                $this->lastAmount($row['last_actual_transfer'] ?? null),
                $row['last_actual_transfer']['days_since'] ?? '',
                implode('; ', $row['holds']),
            ])->all(),
        );

        return $report['census_exceptions'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param array<string, mixed>|null $last */
    private function lastAmount(?array $last): string
    {
        if ($last === null) {
            return '';
        }
        if (($last['amount_foreign'] ?? null) !== null) {
            return number_format((float) $last['amount_foreign'], 2, '.', '').' '.($last['currency'] ?? '');
        }

        return number_format((float) ($last['amount_rub'] ?? 0), 2, '.', '').' RUB';
    }
}
