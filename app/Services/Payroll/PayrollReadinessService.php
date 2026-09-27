<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\BankStatementCredit;
use App\Models\BankStatementImport;
use App\Models\FinanceSnapshot;
use App\Models\MoneyReconException;
use App\Models\MoneyReconRun;
use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use App\Models\User;
use App\Services\Payments\TochkaBalanceService;
use App\Services\PayoutRunService;
use App\Services\TeacherSalaryService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use JsonException;

/**
 * Canonical read-only payload for the accountant cabinet and private export.
 *
 * No method in this service writes a database row. A positive obligation is
 * releasable only when every required source is fresh and the calculation has
 * no warning or exception. Other teachers remain visible with a disposition.
 */
final class PayrollReadinessService
{
    public function __construct(
        private readonly PayoutRunService $payouts,
        private readonly TochkaBalanceService $tochka,
    ) {}

    /** @return array<string, mixed> */
    public function build(?Carbon $cutoff = null): array
    {
        $cutoff ??= Carbon::parse((string) config('payroll_readiness.target_date'))->startOfDay();
        $before = $this->moneyFingerprint();
        $evidence = $this->evidence($cutoff);
        $lineExceptions = $this->paidWithoutAccess();
        $globalHolds = collect($evidence)
            ->filter(fn (array $source): bool => ($source['status'] ?? 'incomplete') !== 'fresh')
            ->keys()
            ->map(fn (string $source): string => 'evidence_'.$source.'_'.($evidence[$source]['status'] ?? 'incomplete'))
            ->values()
            ->all();

        $rows = [];
        foreach (Teacher::query()->orderBy('id')->get() as $teacher) {
            $rows[] = $this->teacherRow($teacher, $cutoff, $globalHolds, $lineExceptions);
        }

        $expected = (int) config('payroll_readiness.expected_teacher_count', 23);
        $censusExceptions = count($rows) === $expected
            ? []
            : [sprintf('teacher_census_count:%d_expected:%d', count($rows), $expected)];

        $rows = $this->applyFunding($rows);
        $sourceFingerprints = $this->sourceFingerprints($evidence);
        $stable = [
            'cutoff' => $cutoff->toIso8601String(),
            'expected_teacher_count' => $expected,
            'actual_teacher_count' => count($rows),
            'census_exceptions' => $censusExceptions,
            'source_fingerprints' => $sourceFingerprints,
            'evidence' => $evidence,
            'line_exceptions' => $lineExceptions,
            'teachers' => $rows,
        ];
        $fingerprint = hash('sha256', $this->canonicalJson($stable));
        $after = $this->moneyFingerprint();

        return $stable + [
            'schema' => 'teacher-payroll-readiness/v1',
            'generated_at' => now()->toIso8601String(),
            'fingerprint' => $fingerprint,
            'totals' => $this->totals($rows),
            'read_only' => ['before' => $before, 'after' => $after, 'moved' => $before !== $after],
        ];
    }

    /** @return array<string, mixed> */
    private function teacherRow(Teacher $teacher, Carbon $cutoff, array $globalHolds, array $lineExceptions): array
    {
        $hasCourses = $teacher->allTaughtCourses()->isNotEmpty();
        $courses = $teacher->allTaughtCourses();
        $calculation = $hasCourses
            ? $this->payouts->runForTeacher($teacher, $cutoff)
            : [
                'teacher_id' => (int) $teacher->id,
                'name' => (string) $teacher->name,
                'lane' => strtoupper((string) ($teacher->payout_currency ?: 'RUB')) === 'EUR' ? 'EUR' : 'RUB',
                'blocks' => [],
                'prior_blocks' => [],
                'payable_rub' => 0.0,
                'payable_eur' => null,
                'warnings' => [],
                'reconciliation_exceptions' => [],
            ];
        $last = $this->lastActualTransfer($teacher);
        $holds = $globalHolds;
        $payableRub = Money::round((float) ($calculation['payable_rub'] ?? 0));
        $payableEur = isset($calculation['payable_eur'])
            ? Money::round((float) $calculation['payable_eur'])
            : null;
        $positive = $payableRub > 0 || ($payableEur ?? 0) > 0;

        if (isset($calculation['error'])) {
            $holds[] = 'calculator:'.(string) $calculation['error'];
        }
        foreach ((array) ($calculation['reconciliation_exceptions'] ?? []) as $exception) {
            $holds[] = 'reconciliation:'.(string) ($exception['type'] ?? 'unknown');
        }
        foreach ((array) ($calculation['warnings'] ?? []) as $warning) {
            $holds[] = 'warning:'.trim((string) $warning);
        }
        $courseIds = $courses->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($lineExceptions as $exception) {
            if (in_array((int) $exception['course_id'], $courseIds, true)) {
                $holds[] = 'paid_without_access:payment_'.(int) $exception['payment_id'];
            }
        }
        $holds = array_values(array_unique($holds));

        $disposition = match (true) {
            isset($calculation['error']) => 'outside_calculator',
            $positive && $holds !== [] => 'held',
            $positive => 'payable',
            ! $hasCourses => 'inactive',
            default => 'zero',
        };
        $dueOn = collect(array_merge(
            (array) ($calculation['prior_blocks'] ?? []),
            (array) ($calculation['blocks'] ?? []),
        ))->pluck('completed_on')->filter()->sort()->first() ?? $cutoff->toDateString();

        $row = [
            'teacher_id' => (int) $teacher->id,
            'name' => (string) $teacher->name,
            'disposition' => $disposition,
            'verification_state' => $disposition === 'payable' ? 'verified' : ($disposition === 'held' ? 'held' : 'not_released'),
            'channel' => ($calculation['lane'] ?? null) === 'EUR' ? 'paypal_mg' : 'tochka_maria',
            'due_on' => (string) $dueOn,
            'payable_rub' => $payableRub,
            'payable_eur' => $payableEur,
            'base_rub' => Money::round((float) ($calculation['base_rub'] ?? 0)),
            'prior_rub' => Money::round((float) ($calculation['prior_rub'] ?? 0)),
            'rate_period' => $calculation['rate_period'] ?? null,
            'advances_total_rub' => Money::round((float) ($calculation['advances_total_rub'] ?? 0)),
            'direct_receipts' => $calculation['direct_receipts'] ?? ['total' => 0, 'lines' => []],
            'holds' => $holds,
            'last_actual_transfer' => $last,
            'calculation' => $calculation,
        ];
        $row['fingerprint'] = hash('sha256', $this->canonicalJson($row));

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function lastActualTransfer(Teacher $teacher): ?array
    {
        /** @var TeacherPayout|null $payout */
        $payout = $teacher->payouts()
            ->where('type', TeacherPayout::TYPE_REGULAR)
            ->whereNotNull('paid_at')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->first();
        if ($payout === null) {
            return null;
        }
        $breakdown = (array) ($payout->breakdown ?? []);

        return [
            // paid_at is the actual transfer date. created_at may be a much
            // later H4597 backfill date and is deliberately never presented.
            'date' => $payout->paid_at?->toDateString(),
            'amount_rub' => Money::round((float) $payout->amount),
            'amount_foreign' => $payout->amount_foreign !== null ? Money::round((float) $payout->amount_foreign) : null,
            'currency' => $payout->payout_currency ?: 'RUB',
            'channel' => strtoupper((string) $payout->payout_currency) === 'EUR' ? 'paypal_mg' : 'tochka_maria',
            'course_id' => $payout->course_id ?? ($breakdown['course_id'] ?? null),
            'block_number' => $breakdown['block_number'] ?? null,
            'rate' => $payout->salary_value !== null ? (float) $payout->salary_value : null,
            'advances' => $breakdown['advances_settled'] ?? $breakdown['advance_settlements'] ?? [],
            'offsets' => [
                'direct_receipts' => $breakdown['direct_receipts'] ?? [],
                'mutual_settlement' => $breakdown['mutual_settlement_offset'] ?? null,
            ],
            'evidence_reference' => $breakdown['source_quote'] ?? $payout->comment,
            'is_historical_backfill' => str_starts_with((string) $payout->comment, BackfillHistoryService::COMMENT_MARKER),
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function evidence(Carbon $cutoff): array
    {
        $manifest = $this->manifestEvidence($cutoff);
        $paypal = FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_PAYPAL_BALANCE);
        $freshAfter = $cutoff->copy()->subDays((int) config('payroll_readiness.evidence_max_age_days', 7))->startOfDay();
        $paypalFresh = $paypal?->entered_at !== null && $paypal->entered_at->gte($freshAfter);
        $bankCoverage = BankStatementImport::query()
            ->whereDate('covers_from', '<=', (string) config('payroll_readiness.evidence_from'))
            ->whereDate('covers_to', '>=', $cutoff->toDateString())
            ->orderByDesc('id')
            ->first();
        $reconciliation = MoneyReconRun::query()
            ->whereDate('business_date', '<=', $cutoff->toDateString())
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->first();
        $openByType = MoneyReconException::query()
            ->where('state', MoneyReconException::OPEN)
            ->selectRaw('type, COUNT(*) AS aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type')
            ->map(fn ($count): int => (int) $count)
            ->sortKeys()
            ->all();
        $reconciliationFresh = (bool) config('features.money_daily_reconciliation')
            && $reconciliation?->status === MoneyReconRun::COMPLETE
            && $reconciliation->business_date?->toDateString() === $cutoff->toDateString();

        return [
            'private_manifest' => $manifest,
            'bank_statement' => [
                'status' => $bankCoverage !== null && (bool) config('features.money_bank_statement_credits') ? 'fresh' : 'incomplete',
                'covered_from' => $bankCoverage?->covers_from?->toDateString(),
                'covered_to' => $bankCoverage?->covers_to?->toDateString(),
                'source_hash' => $bankCoverage?->file_sha256,
                'note' => $bankCoverage === null ? 'fresh August-September bank credits not imported' : ((bool) config('features.money_bank_statement_credits') ? null : 'bank source flag is off'),
            ],
            'paypal_xoom' => [
                'status' => $paypalFresh && ($manifest['status'] ?? null) === 'fresh' ? 'fresh' : 'incomplete',
                'as_of' => $paypal?->entered_at?->toIso8601String(),
                'balance_eur' => $paypal?->majorAmount(),
                'note' => $paypalFresh ? null : 'fresh PayPal/Xoom balance evidence is missing',
            ],
            'reconciliation' => [
                'status' => $reconciliationFresh ? 'fresh' : 'incomplete',
                'business_date' => $reconciliation?->business_date?->toDateString(),
                'input_fingerprint' => $reconciliation?->input_fingerprint,
                'totals_checksum' => $reconciliation?->totals_checksum,
                'open_exceptions_by_type' => $openByType,
                'note' => $reconciliationFresh ? null : 'complete transfer-day reconciliation is missing or disabled',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function manifestEvidence(Carbon $cutoff): array
    {
        $path = (string) config('payroll_readiness.evidence_manifest_path');
        if ($path === '' || ! File::isFile($path)) {
            return ['status' => 'incomplete', 'path' => $path, 'note' => 'private payout evidence manifest is missing'];
        }
        try {
            $raw = File::get($path);
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['status' => 'incomplete', 'path' => $path, 'note' => 'private payout evidence manifest is invalid JSON'];
        }
        $required = ['payout_sheets', 'bank_credit', 'paypal_xoom'];
        $sources = (array) ($data['sources'] ?? []);
        $hashes = [];
        try {
            $complete = collect($required)->every(function (string $key) use ($sources, $cutoff, &$hashes): bool {
                $source = (array) ($sources[$key] ?? []);
                $hash = strtolower((string) ($source['sha256'] ?? ''));
                if (! preg_match('/^[a-f0-9]{64}$/', $hash) || in_array($hash, $hashes, true)) {
                    return false;
                }
                $hashes[] = $hash;
                $asOf = Carbon::parse((string) ($source['as_of'] ?? ''));

                return $asOf->betweenIncluded(
                    $cutoff->copy()->subDays((int) config('payroll_readiness.evidence_max_age_days', 7)),
                    $cutoff,
                );
            });
        } catch (\Throwable) {
            $complete = false;
        }

        return [
            'status' => $complete ? 'fresh' : 'incomplete',
            'path' => $path,
            'sha256' => hash('sha256', $raw),
            'generated_at' => $data['generated_at'] ?? null,
            'sources' => $sources,
            'note' => $complete ? null : 'required source hash/date is missing, stale, future-dated, invalid, or replayed',
        ];
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function applyFunding(array $rows): array
    {
        $tochka = $this->tochka->snapshot();
        $paypal = FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_PAYPAL_BALANCE);
        $remaining = [
            'tochka_maria' => ($tochka['ok'] ?? false) ? (float) ($tochka['closing_total'] ?? 0) : null,
            'paypal_mg' => $paypal?->majorAmount(),
        ];
        usort($rows, fn (array $a, array $b): int => [$a['due_on'], $a['teacher_id']] <=> [$b['due_on'], $b['teacher_id']]);
        foreach ($rows as &$row) {
            $channel = (string) $row['channel'];
            $need = $channel === 'paypal_mg' ? (float) ($row['payable_eur'] ?? 0) : (float) $row['payable_rub'];
            if ($row['disposition'] !== 'payable' || $need <= 0) {
                $row['funding_state'] = 'not_applicable';
                $row['remaining_obligation'] = $row['disposition'] === 'held' ? $need : 0.0;
            } elseif ($remaining[$channel] === null) {
                $row['funding_state'] = 'unknown';
                $row['remaining_obligation'] = $need;
            } elseif ($need <= $remaining[$channel] + 0.0001) {
                $remaining[$channel] = Money::round($remaining[$channel] - $need);
                $row['funding_state'] = 'funded';
                $row['remaining_obligation'] = 0.0;
            } else {
                $row['funding_state'] = 'unfunded';
                $row['remaining_obligation'] = Money::round($need);
            }
        }
        unset($row);

        foreach ($rows as &$row) {
            unset($row['fingerprint']);
            $row['fingerprint'] = hash('sha256', $this->canonicalJson($row));
        }
        unset($row);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function sourceFingerprints(array $evidence): array
    {
        return [
            'money' => $this->moneyFingerprint(),
            'rates_sha256' => hash_file('sha256', config_path('teacher_rates.php')),
            'manifest_sha256' => $evidence['private_manifest']['sha256'] ?? null,
        ];
    }

    /** @return list<array<string, int|float|string|null>> */
    private function paidWithoutAccess(): array
    {
        $excluded = array_values(array_unique(array_merge(
            ['deposit', 'trial'],
            TeacherSalaryService::NON_REVENUE_TARIFFS,
        )));

        return Payment::query()
            ->paid()
            ->real()
            ->whereNotNull('user_id')
            ->whereNotNull('course_id')
            ->whereNotIn('tariff', $excluded)
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('course_group')
                    ->whereColumn('course_group.course_id', 'payments.course_id');
            })
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('group_user')
                    ->join('course_group', 'course_group.group_id', '=', 'group_user.group_id')
                    ->whereColumn('group_user.user_id', 'payments.user_id')
                    ->whereColumn('course_group.course_id', 'payments.course_id');
            })
            ->orderBy('id')
            ->get(['id', 'user_id', 'course_id', 'tariff', 'amount'])
            ->map(fn (Payment $payment): array => [
                'type' => 'paid_without_access',
                'payment_id' => (int) $payment->id,
                'user_id' => (int) $payment->user_id,
                'course_id' => (int) $payment->course_id,
                'tariff' => (string) $payment->tariff,
                'amount_rub' => Money::round((float) $payment->amount),
            ])
            ->all();
    }

    /** @return array<string, int|string|null> */
    private function moneyFingerprint(): array
    {
        return [
            'payments_count' => Payment::query()->count(),
            'payments_max_id' => Payment::query()->max('id'),
            'payouts_count' => TeacherPayout::query()->count(),
            'payouts_max_id' => TeacherPayout::query()->max('id'),
            'users_count' => User::query()->count(),
            'bank_imports_count' => BankStatementImport::query()->count(),
            'bank_credits_count' => BankStatementCredit::query()->count(),
            'snapshots_count' => FinanceSnapshot::query()->count(),
        ];
    }

    /** @param list<array<string, mixed>> $rows @return array<string, mixed> */
    private function totals(array $rows): array
    {
        return [
            'by_disposition' => collect($rows)->countBy('disposition')->sortKeys()->all(),
            'released_rub' => Money::round((float) collect($rows)->where('disposition', 'payable')->sum('payable_rub')),
            'released_eur' => Money::round((float) collect($rows)->where('disposition', 'payable')->sum('payable_eur')),
            'held_rub' => Money::round((float) collect($rows)->where('disposition', 'held')->sum('payable_rub')),
            'held_eur' => Money::round((float) collect($rows)->where('disposition', 'held')->sum('payable_eur')),
        ];
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
