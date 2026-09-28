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
        $receiptExceptions = $this->receiptEvidenceExceptions($cutoff, $evidence);
        $globalHolds = collect($evidence)
            ->filter(fn (array $source): bool => ($source['scope'] ?? 'global') === 'global'
                && ($source['status'] ?? 'incomplete') !== 'fresh')
            ->keys()
            ->map(fn (string $source): string => 'evidence_'.$source.'_'.($evidence[$source]['status'] ?? 'incomplete'))
            ->values()
            ->all();

        $rows = [];
        foreach (Teacher::query()->orderBy('id')->get() as $teacher) {
            $rows[] = $this->teacherRow($teacher, $cutoff, $globalHolds, $lineExceptions, $receiptExceptions, $evidence);
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
            'receipt_exceptions' => $receiptExceptions,
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
    private function teacherRow(Teacher $teacher, Carbon $cutoff, array $globalHolds, array $lineExceptions, array $receiptExceptions, array $evidence): array
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
        $last = $this->lastActualTransfer($teacher, $cutoff, $calculation);
        $holds = $globalHolds;
        $amounts = $this->reportableAmounts($calculation);
        $payableRub = $amounts['payable_rub'];
        $payableEur = $amounts['payable_eur'];
        $positive = ($payableRub ?? 0) > 0 || ($payableEur ?? 0) > 0;
        $unreconciledPositive = $amounts['amount_state'] === 'partial_current_window'
            && (float) $amounts['legacy_candidate_rub'] > 0;

        if (isset($calculation['error'])) {
            $holds[] = 'calculator:'.(string) $calculation['error'];
        }
        foreach ((array) ($calculation['reconciliation_exceptions'] ?? []) as $exception) {
            $holds[] = 'reconciliation:'.(string) ($exception['type'] ?? 'unknown');
        }
        foreach ((array) ($calculation['warnings'] ?? []) as $warning) {
            $holds[] = 'warning:'.trim((string) $warning);
        }
        foreach ($amounts['holds'] as $hold) {
            $holds[] = $hold;
        }
        $courseIds = $courses->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($lineExceptions as $exception) {
            if (in_array((int) $exception['course_id'], $courseIds, true)) {
                $holds[] = 'paid_without_access:payment_'.(int) $exception['payment_id'];
            }
        }
        $calculationPaymentIds = collect((array) ($calculation['blocks'] ?? []))
            ->flatMap(fn (array $block) => (array) ($block['lines'] ?? []))
            ->pluck('payment_id')->filter()->map(fn ($id): int => (int) $id)->unique()->all();
        foreach ($receiptExceptions as $exception) {
            if (in_array((int) $exception['payment_id'], $calculationPaymentIds, true)) {
                $holds[] = 'receipt_evidence:'.(string) $exception['type'].'_payment_'.(int) $exception['payment_id'];
            }
        }
        $channel = $this->channelFor($calculation);
        if ($channel === 'xoom_mg' && ($evidence['xoom_edgar']['status'] ?? 'incomplete') !== 'fresh') {
            $holds[] = 'evidence_xoom_edgar_'.($evidence['xoom_edgar']['status'] ?? 'incomplete');
        }
        $holds = array_values(array_unique($holds));

        $disposition = match (true) {
            isset($calculation['error']) => 'outside_calculator',
            ($positive || $unreconciledPositive) && $holds !== [] => 'held',
            $positive => 'payable',
            ! $hasCourses => 'inactive',
            default => 'zero',
        };
        $dueOn = collect((array) ($calculation['blocks'] ?? []))
            ->pluck('completed_on')->filter()->sort()->first() ?? $cutoff->toDateString();

        $row = [
            'teacher_id' => (int) $teacher->id,
            'name' => (string) $teacher->name,
            'disposition' => $disposition,
            'verification_state' => $disposition === 'payable' ? 'verified' : ($disposition === 'held' ? 'held' : 'not_released'),
            'channel' => $channel,
            'due_on' => (string) $dueOn,
            'payable_rub' => $payableRub,
            'payable_eur' => $payableEur,
            'amount_state' => $amounts['amount_state'],
            'amount_basis' => $amounts['amount_basis'],
            'legacy_candidate_rub' => $amounts['legacy_candidate_rub'],
            'excluded_prior_rub' => $amounts['excluded_prior_rub'],
            'net_after_npd_rub' => $amounts['net_after_npd_rub'],
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

    /**
     * The legacy engine adds every uncovered pre-cutoff receipt to the current
     * salary. That is unsafe for teachers historically paid outside
     * teacher_payouts: an unbackfilled payout makes already-paid years look due
     * again. The readiness surface therefore reports only the current window
     * for percentage rows and exposes the legacy total as an unreconciled
     * candidate. Fixed rows with no completed block in the window are zero.
     *
     * @return array{payable_rub: float|null, payable_eur: float|null, amount_state: string, amount_basis: string, legacy_candidate_rub: float, excluded_prior_rub: float, net_after_npd_rub: float|null, holds: list<string>}
     */
    private function reportableAmounts(array $calculation): array
    {
        $legacyRub = Money::round((float) ($calculation['payable_rub'] ?? 0));
        $legacyEur = isset($calculation['payable_eur'])
            ? Money::round((float) $calculation['payable_eur'])
            : null;
        $priorRub = Money::round((float) ($calculation['prior_rub'] ?? 0));
        $period = (array) ($calculation['rate_period'] ?? []);
        $blocks = (array) ($calculation['blocks'] ?? []);
        $currentCycleStart = Carbon::parse((string) config('payroll_readiness.evidence_from'))->startOfDay();
        $currentCycleBlocks = collect($blocks)->filter(function (array $block) use ($currentCycleStart): bool {
            $completedOn = $block['completed_on'] ?? null;

            return is_string($completedOn) && Carbon::parse($completedOn)->gte($currentCycleStart);
        });

        if (($period['kind'] ?? null) === 'fixed_monthly' && $currentCycleBlocks->isEmpty()) {
            return [
                'payable_rub' => 0.0,
                'payable_eur' => null,
                'amount_state' => 'calculated',
                'amount_basis' => 'no_completed_block_in_current_window',
                'legacy_candidate_rub' => $legacyRub,
                'excluded_prior_rub' => $priorRub,
                'net_after_npd_rub' => null,
                'holds' => ['seasonal:no_completed_block_in_current_window'],
            ];
        }

        if (! isset($period['value_pct'])) {
            $npdPct = isset($calculation['npd_pct']) ? (float) $calculation['npd_pct'] : null;

            return [
                'payable_rub' => $legacyRub,
                'payable_eur' => $legacyEur,
                'amount_state' => 'calculated',
                'amount_basis' => 'legacy_engine_without_unreconciled_prior',
                'legacy_candidate_rub' => $legacyRub,
                'excluded_prior_rub' => 0.0,
                'net_after_npd_rub' => $npdPct !== null
                    ? Money::round($legacyRub * (1 - $npdPct / 100.0))
                    : null,
                'holds' => [],
            ];
        }

        $slice = (float) ($period['bank_slice_pct'] ?? 100.0) / 100.0;
        $rate = (float) $period['value_pct'] / 100.0;
        $direct = (array) ($calculation['direct_receipts'] ?? []);
        $directRubOffset = (float) ($direct['rub_offset'] ?? 0);
        $foreignOffset = (float) ($direct['eur_offset'] ?? 0);
        $fx = (float) (($calculation['fx']['rate'] ?? 0));
        $holds = $priorRub > 0 ? ['reconciliation:uncovered_pre_cutoff_revenue'] : [];
        if ($foreignOffset > 0 && $fx <= 0) {
            $holds[] = 'reconciliation:direct_foreign_without_fx';

            return [
                'payable_rub' => null,
                'payable_eur' => null,
                'amount_state' => 'incomplete',
                'amount_basis' => 'current_window_fx_missing',
                'legacy_candidate_rub' => $legacyRub,
                'excluded_prior_rub' => $priorRub,
                'net_after_npd_rub' => null,
                'holds' => $holds,
            ];
        }
        $directForeignRub = $foreignOffset * $fx;
        $directRevenueRub = $directRubOffset + $directForeignRub;
        $payableRub = (float) ($calculation['base_rub'] ?? 0) * $slice * $rate;
        // Direct receipts earn the normal teacher percentage without the bank
        // slice, then the cash already held by the teacher is offset once.
        $payableRub += $directRevenueRub * $rate;
        $payableRub -= $directRevenueRub;
        $payableRub -= (float) ($calculation['advances_total_rub'] ?? 0);
        if ($payableRub < 0) {
            $holds[] = 'reconciliation:negative_current_window_payable';
            $payableRub = 0.0;
        }
        $payableRub = Money::round($payableRub);
        $payableEur = ($calculation['lane'] ?? null) === 'EUR' && $fx > 0
            ? Money::round($payableRub / $fx)
            : null;
        $npdPct = isset($calculation['npd_pct']) ? (float) $calculation['npd_pct'] : null;

        return [
            'payable_rub' => $payableRub,
            'payable_eur' => $payableEur,
            'amount_state' => $priorRub > 0 ? 'partial_current_window' : 'calculated',
            'amount_basis' => $priorRub > 0
                ? 'current_window_only_excludes_unreconciled_prior'
                : 'current_window_recomputed',
            'legacy_candidate_rub' => $legacyRub,
            'excluded_prior_rub' => $priorRub,
            'net_after_npd_rub' => $npdPct !== null ? Money::round($payableRub * (1 - $npdPct / 100.0)) : null,
            'holds' => $holds,
        ];
    }

    /** @return array<string, mixed>|null */
    private function lastActualTransfer(Teacher $teacher, Carbon $cutoff, array $calculation): ?array
    {
        /** @var TeacherPayout|null $payout */
        $payout = $teacher->payouts()
            ->where('type', TeacherPayout::TYPE_REGULAR)
            ->whereNotNull('paid_at')
            ->where(function ($query): void {
                $query->where('amount', '>', 0)
                    ->orWhere('amount_foreign', '>', 0);
            })
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
            'days_since' => $payout->paid_at === null
                ? null
                : max(0, (int) $payout->paid_at->copy()->startOfDay()->diffInDays($cutoff->copy()->startOfDay())),
            'amount_rub' => Money::round((float) $payout->amount),
            'amount_foreign' => $payout->amount_foreign !== null ? Money::round((float) $payout->amount_foreign) : null,
            'currency' => $payout->payout_currency ?: 'RUB',
            'channel' => $this->channelFor($calculation),
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

        $paypal = $this->paypalStudentEvidence($cutoff);

        return [
            'private_manifest' => $manifest + ['scope' => 'global'],
            'tochka_bank_credits' => [
                'status' => $bankCoverage !== null && (bool) config('features.money_bank_statement_credits') ? 'fresh' : 'incomplete',
                'scope' => 'payment_line',
                'covered_from' => $bankCoverage?->covers_from?->toDateString(),
                'covered_to' => $bankCoverage?->covers_to?->toDateString(),
                'source_hash' => $bankCoverage?->file_sha256,
                'note' => $bankCoverage === null ? 'fresh August-September bank credits not imported' : ((bool) config('features.money_bank_statement_credits') ? null : 'bank source flag is off'),
            ],
            'paypal_student_notifications' => $paypal + ['scope' => 'payment_line'],
            'xoom_edgar' => $this->manifestSource($manifest, 'xoom_edgar', $cutoff) + ['scope' => 'teacher_line'],
            'reconciliation' => [
                'status' => $reconciliationFresh ? 'fresh' : 'incomplete',
                'scope' => 'global',
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
        $required = ['payout_sheets'];
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

    /** @return array<string, mixed> */
    private function manifestSource(array $manifest, string $key, Carbon $cutoff): array
    {
        $source = (array) (($manifest['sources'] ?? [])[$key] ?? []);
        $hash = strtolower((string) ($source['sha256'] ?? ''));
        try {
            $asOf = Carbon::parse((string) ($source['as_of'] ?? ''));
            $fresh = preg_match('/^[a-f0-9]{64}$/', $hash) === 1
                && $asOf->betweenIncluded(
                    $cutoff->copy()->subDays((int) config('payroll_readiness.evidence_max_age_days', 7)),
                    $cutoff,
                );
        } catch (\Throwable) {
            $fresh = false;
        }

        return [
            'status' => $fresh ? 'fresh' : 'incomplete',
            'as_of' => $source['as_of'] ?? null,
            'sha256' => $source['sha256'] ?? null,
            'note' => $fresh ? null : "private {$key} evidence is missing, stale, future-dated, or invalid",
        ];
    }

    /** @return array<string, mixed> */
    private function paypalStudentEvidence(Carbon $cutoff): array
    {
        $rows = $this->probablePaypalPayments($cutoff);
        $verified = $rows->filter(fn (Payment $payment): bool => $this->hasPaypalNotificationEvidence($payment));
        $last = $rows->max(fn (Payment $payment) => ($payment->first_paid_at ?? $payment->created_at)?->toIso8601String());

        return [
            'status' => $rows->count() > 0 && $verified->count() === $rows->count() ? 'fresh' : 'incomplete',
            'receipt_count' => $rows->count(),
            'verified_count' => $verified->count(),
            'unresolved_count' => $rows->count() - $verified->count(),
            'by_month' => $rows->countBy(fn (Payment $payment): string => ($payment->first_paid_at ?? $payment->created_at)?->format('Y-m') ?? 'unknown')->sortKeys()->all(),
            'as_of' => $last,
            'note' => $rows->count() === 0
                ? 'no August-September PayPal receipts found; absence is not treated as zero'
                : ($verified->count() === $rows->count() ? null : ($rows->count() - $verified->count()).' receipt(s) lack a PayPal transaction notification/reference'),
        ];
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Payment> */
    private function probablePaypalPayments(Carbon $cutoff)
    {
        return Payment::query()
            ->paid()->real()
            ->whereNotNull('foreign_amount')
            ->whereNotNull('foreign_currency')
            ->where(function ($query): void {
                $query->whereNull('provider')->orWhere('provider', Payment::PROVIDER_PAYPAL);
            })
            ->whereRaw('COALESCE(first_paid_at, created_at) >= ?', [Carbon::parse((string) config('payroll_readiness.evidence_from'))->startOfDay()->toDateTimeString()])
            ->whereRaw('COALESCE(first_paid_at, created_at) < ?', [$cutoff->copy()->startOfDay()->toDateTimeString()])
            ->orderBy('id')
            ->get();
    }

    private function hasPaypalNotificationEvidence(Payment $payment): bool
    {
        if ($payment->provider !== Payment::PROVIDER_PAYPAL) {
            return false;
        }
        $meta = is_array($payment->claim_meta) ? $payment->claim_meta : [];

        return filled($meta['paypal_payer'] ?? null)
            && filled($meta['paid_on'] ?? null)
            && filled($meta['txn'] ?? null);
    }

    /** @return list<array{type: string, payment_id: int, course_id: int|null}> */
    private function receiptEvidenceExceptions(Carbon $cutoff, array $evidence): array
    {
        $out = $this->probablePaypalPayments($cutoff)
            ->reject(fn (Payment $payment): bool => $this->hasPaypalNotificationEvidence($payment))
            ->map(fn (Payment $payment): array => [
                'type' => 'paypal_student_receipt_missing_notification',
                'payment_id' => (int) $payment->id,
                'course_id' => $payment->course_id !== null ? (int) $payment->course_id : null,
            ])->values()->all();

        if (($evidence['tochka_bank_credits']['status'] ?? 'incomplete') !== 'fresh') {
            $special = [
                Payment::PROVIDER_PAYPAL,
                Payment::PROVIDER_PAYPAL_SUBSCRIPTION,
                Payment::PROVIDER_INVOICE,
                Payment::PROVIDER_BANK_SEPA,
                Payment::PROVIDER_TEACHER_TRANSFER,
            ];
            $bank = Payment::query()->paid()->real()
                ->whereNull('foreign_amount')->whereNull('foreign_currency')
                ->where(function ($query) use ($special): void {
                    $query->whereNull('provider')->orWhereNotIn('provider', $special);
                })
                ->where('received_account', '!=', Payment::RECEIVED_TEACHER)
                ->whereRaw('COALESCE(first_paid_at, created_at) >= ?', [Carbon::parse((string) config('payroll_readiness.evidence_from'))->startOfDay()->toDateTimeString()])
                ->whereRaw('COALESCE(first_paid_at, created_at) < ?', [$cutoff->copy()->startOfDay()->toDateTimeString()])
                ->get(['id', 'course_id'])
                ->map(fn (Payment $payment): array => [
                    'type' => 'tochka_bank_credit_not_imported',
                    'payment_id' => (int) $payment->id,
                    'course_id' => $payment->course_id !== null ? (int) $payment->course_id : null,
                ])->all();
            $out = array_merge($out, $bank);
        }

        return $out;
    }

    /** @param array<string, mixed> $calculation */
    private function channelFor(array $calculation): string
    {
        $slug = (string) ($calculation['slug'] ?? '');
        $override = (array) config('payroll_readiness.channel_overrides', []);
        if ($slug !== '' && isset($override[$slug])) {
            return (string) $override[$slug];
        }
        $configured = $slug !== '' ? config("teacher_rates.recipients.{$slug}.channel") : null;
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return ($calculation['lane'] ?? null) === 'EUR' ? 'paypal_mg' : 'tochka_maria';
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private function applyFunding(array $rows): array
    {
        $tochka = $this->tochka->snapshot();
        $paypal = FinanceSnapshot::latestOfType(FinanceSnapshot::TYPE_PAYPAL_BALANCE);
        $remaining = [
            'tochka_maria' => ($tochka['ok'] ?? false) ? (float) ($tochka['closing_total'] ?? 0) : null,
            'tochka_ip_gasuns' => ($tochka['ok'] ?? false) ? (float) ($tochka['closing_total'] ?? 0) : null,
            'paypal_mg' => $paypal?->majorAmount(),
            'xoom_mg' => null,
        ];
        usort($rows, fn (array $a, array $b): int => [$a['due_on'], $a['teacher_id']] <=> [$b['due_on'], $b['teacher_id']]);
        foreach ($rows as &$row) {
            $channel = (string) $row['channel'];
            $need = in_array($channel, ['paypal_mg', 'xoom_mg'], true) ? (float) ($row['payable_eur'] ?? 0) : (float) $row['payable_rub'];
            if ($row['disposition'] !== 'payable' || $need <= 0) {
                $row['funding_state'] = 'not_applicable';
                $row['remaining_obligation'] = $row['amount_state'] === 'incomplete'
                    ? null
                    : ($row['disposition'] === 'held' ? $need : 0.0);
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
            'unreconciled_legacy_candidate_rub' => Money::round((float) collect($rows)->sum('excluded_prior_rub')),
        ];
    }

    private function canonicalJson(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
