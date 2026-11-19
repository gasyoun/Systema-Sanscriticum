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
        // H7205 P2: the command is part of the gated readiness surface, not a
        // backdoor around the accountant page's flag check.
        if (! (bool) config('features.teacher_payroll_readiness')) {
            $this->error('Feature teacher_payroll_readiness is OFF; refusing to run the payroll readiness census');

            return self::FAILURE;
        }

        $cutoff = filled($this->option('on'))
            ? Carbon::parse((string) $this->option('on'))
            : Carbon::parse((string) config('payroll_readiness.target_date'));
        $report = $service->build($cutoff);

        if ($report['read_only']['moved']) {
            $this->error('READ-ONLY VIOLATION: a money-table fingerprint moved during the census');

            return self::FAILURE;
        }

        $export = $this->option('export');
        $wantsExport = $export !== null && $export !== '';
        $exportPath = $wantsExport
            ? ($export === 'auto' ? $this->autoExportPath($cutoff) : (string) $export)
            : null;
        // The artifact the transfer-time recheck reads: the export target, or
        // the cutoff's canonical auto path when no export is requested.
        $artifactPath = $exportPath ?? $this->autoExportPath($cutoff);

        if ($exportPath !== null && $this->isPublicPath($exportPath)) {
            $this->error('Private payroll export cannot be written under a public directory');

            return self::FAILURE;
        }

        // H7205 P0-1: an approved private export is write-once. A later run
        // for the same cutoff must never replace the approved artifact in
        // place — the field already lost one approval this way.
        if ($exportPath !== null && File::isFile($exportPath)) {
            $incumbent = $this->artifactFingerprint($exportPath);
            $this->error(sprintf(
                'APPROVED EXPORT EXISTS: %s already carries fingerprint %s; live recomputation is %s; refusing to overwrite. Move the incumbent artifact aside, or export to a run-unique path, to re-approve deliberately.',
                $exportPath,
                $incumbent ?? '<unreadable>',
                $report['fingerprint'],
            ));

            return self::FAILURE;
        }

        $expected = trim((string) ($this->option('expect-fingerprint') ?? ''));
        if ($expected !== '') {
            // H7205 P0-1: the check must read the artifact on disk. A
            // replaced approval fails here even when the live recomputation
            // still matches the operator's string.
            if (File::isFile($artifactPath)) {
                $artifact = $this->artifactFingerprint($artifactPath);
                if ($artifact === null || ! hash_equals($expected, $artifact)) {
                    $this->error(sprintf(
                        'REPLACED ARTIFACT: the artifact on disk (%s) carries %s, not the approved %s',
                        $artifactPath,
                        $artifact ?? '<unreadable>',
                        $expected,
                    ));

                    return self::FAILURE;
                }
            }
            if (! hash_equals($expected, (string) $report['fingerprint'])) {
                $this->error('STALE APPROVAL: live fingerprint differs; regenerate and re-approve this row/package');

                return self::FAILURE;
            }
        }

        if ($exportPath !== null) {
            File::ensureDirectoryExists(dirname($exportPath), 0700, true);
            File::put($exportPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $this->info('Private export: '.$exportPath);
        }

        // H7205 P1: bind the verified manifest's source hashes to this
        // approval so a later manifest cannot replay them.
        $service->recordEvidenceHashLedger($report['evidence']['private_manifest'] ?? []);

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

    private function autoExportPath(Carbon $cutoff): string
    {
        return storage_path('app/private/payroll/readiness-'.$cutoff->format('Ymd-His').'.json');
    }

    /**
     * H7205 P2: refuse any path whose resolved location lands under the
     * public directory. The old substring guard missed relative paths such
     * as "public/payroll.json", which never contained "/public/".
     */
    private function isPublicPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', $path);
        if (! str_starts_with($normalized, '/')) {
            $normalized = str_replace('\\', '/', (string) getcwd()).'/'.$normalized;
        }
        if (str_contains($normalized, '/public/')) {
            return true;
        }
        $public = str_replace('\\', '/', (string) (realpath(public_path()) ?: public_path()));
        foreach ([realpath($normalized), realpath(dirname($normalized))] as $resolved) {
            if ($resolved === false) {
                continue;
            }
            $resolved = str_replace('\\', '/', (string) $resolved);
            if ($resolved === $public || str_starts_with($resolved.'/', $public.'/')) {
                return true;
            }
        }

        return false;
    }

    /** @return string|null the fingerprint carried by a private export artifact */
    private function artifactFingerprint(string $path): ?string
    {
        try {
            $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
        $fingerprint = $data['fingerprint'] ?? null;

        return is_string($fingerprint) && $fingerprint !== '' ? $fingerprint : null;
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
