<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\MoneyReconException;
use App\Models\MoneyReconRun;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * H5445 (P3, D1): ежедневная оперативная сверка доказательств (Точка, PayPal,
 * ручные заявки, прямые платежи преподавателю, возвраты, журнал вебхуков)
 * с субрегистром P1 и пакетами выплат. Официальную бухгалтерию не
 * воспроизводит — только операционную правду: каждая строка окна получает
 * ровно один класс (matched / unallocated / exception:<тип>), итоги
 * сходятся тождествами, срез хэшируется.
 *
 * Идемпотентность: прогон = (операционный день, отпечаток входа). Повтор с тем
 * же входом не пишет ничего и сверяет контрольную сумму с сохранённой —
 * несовпадение = дрейф (FAIL). Новый вход — новый прогон; исключения по
 * неизменившимся доказательствам не открываются заново (ключ тот же).
 */
final class DailyReconciler
{
    public const OUTCOME_RECORDED = 'recorded';

    public const OUTCOME_REPLAY = 'replay';

    public const OUTCOME_DRIFT = 'drift';

    public const OUTCOME_DRY = 'dry_run';

    public function __construct(
        private readonly EvidenceCollector $collector,
        private readonly EvidenceClassifier $classifier,
        private readonly ExceptionQueue $queue,
        private readonly BankStatementControl $statement,
    ) {}

    /**
     * @return array<string, mixed> отчёт прогона
     */
    public function run(CarbonInterface $businessDate, bool $allHistory, bool $persist, string $mode): array
    {
        $tz = (string) config('app.timezone');
        $day = CarbonImmutable::parse($businessDate->toDateString(), $tz)->startOfDay();
        $from = $allHistory ? null : $day;
        $to = $day->addDay();

        $input = $this->collector->collect($from, $to);

        $rowsOut = [];
        $findings = [];
        foreach ($input['rows'] as $row) {
            $c = $this->classifier->classify($row, $input['ledger']);
            $rowsOut[] = ['ref' => $row['ref'], 'source' => $row['source'], 'class' => $c['class'], 'amount_kopecks' => $row['amount_kopecks'] ?? null];
            array_push($findings, ...$c['findings']);
        }
        array_push($findings, ...$this->classifier->ledgerFindings($input['ledger']));

        $missing = array_keys(array_filter($input['sources'], fn ($s) => in_array($s['status'], ['missing', 'dark'], true)));
        sort($missing);
        $status = $missing === [] ? MoneyReconRun::COMPLETE : MoneyReconRun::INCOMPLETE;

        $totals = $this->totals($input, $rowsOut, $findings);
        $fingerprint = Canonical::sha256([
            'window' => $input['window'],
            'all_history' => $allHistory,
            'sources' => $input['sources'],
            'rows' => $input['rows'],
            'excluded' => $input['excluded'],
            'ledger' => $input['ledger'],
        ]);
        $checksum = Canonical::sha256($totals);

        $keys = array_map(fn ($f) => ExceptionQueue::key($f), $findings);
        $known = $keys === [] ? [] : MoneyReconException::query()->whereIn('exception_key', $keys)->pluck('exception_key')->all();
        $newKeys = array_values(array_diff(array_unique($keys), $known));

        $report = [
            'business_date' => $day->toDateString(),
            'all_history' => $allHistory,
            'mode' => $mode,
            'status' => $status,
            'missing_sources' => $missing,
            'sources' => $input['sources'],
            'input_fingerprint' => $fingerprint,
            'totals_checksum' => $checksum,
            'totals' => $totals,
            'findings' => count($findings),
            'new_exceptions' => count($newKeys),
            'new_exception_types' => $this->countByType($findings, $newKeys),
            'identity_ok' => $totals['identity']['ok'],
        ];

        if (! $persist) {
            return $report + ['outcome' => self::OUTCOME_DRY];
        }

        if (($seen = $this->alreadyRecorded($day, $fingerprint, $checksum)) !== null) {
            return $seen + $report; // исход повтора перекрывает посчитанные «новые»
        }

        $previous = MoneyReconRun::query()->orderByDesc('id')->first(['id', 'status', 'sources']);

        try {
            $run = $this->record($day, $mode, $status, $fingerprint, $checksum, $input, $totals, $findings, $newKeys);
        } catch (UniqueConstraintViolationException $e) {
            // Параллельный прогон с тем же входом успел раньше (ручной поверх
            // планового): это повтор, а не падение. Транзакция откатилась целиком.
            $seen = $this->alreadyRecorded($day, $fingerprint, $checksum);
            if ($seen === null) {
                throw $e;
            }

            return $seen + $report; // исход повтора перекрывает посчитанные «новые»
        }

        return $report + [
            'outcome' => self::OUTCOME_RECORDED,
            'run_id' => $run->id,
            'status_changed' => $previous === null || $previous->status !== $status
                || array_keys(array_filter($previous->sources ?? [], fn ($s) => in_array($s['status'] ?? null, ['missing', 'dark'], true))) != $missing,
        ];
    }

    /** @return array<string, mixed>|null исход повтора/дрейфа, если прогон с этим входом уже записан */
    private function alreadyRecorded(CarbonImmutable $day, string $fingerprint, string $checksum): ?array
    {
        $existing = MoneyReconRun::query()
            ->whereDate('business_date', $day->toDateString())
            ->where('input_fingerprint', $fingerprint)
            ->first();
        if ($existing === null) {
            return null;
        }

        return [
            'outcome' => $existing->totals_checksum === $checksum ? self::OUTCOME_REPLAY : self::OUTCOME_DRIFT,
            'run_id' => $existing->id,
            'stored_checksum' => $existing->totals_checksum,
            // Повтор ничего не открывает и ни о чём не алертит.
            'new_exceptions' => 0,
            'new_exception_types' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $totals
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $newKeys
     */
    private function record(CarbonImmutable $day, string $mode, string $status, string $fingerprint, string $checksum, array $input, array $totals, array $findings, array $newKeys): MoneyReconRun
    {
        return DB::transaction(function () use ($day, $mode, $status, $fingerprint, $checksum, $input, $totals, $findings, $newKeys): MoneyReconRun {
            $run = MoneyReconRun::query()->create([
                'business_date' => $day->toDateString(),
                'mode' => $mode,
                'status' => $status,
                'input_fingerprint' => $fingerprint,
                'totals_checksum' => $checksum,
                'sources' => $input['sources'],
                'totals' => $totals,
                'classification' => $totals['classes'],
                'exceptions_opened' => count($newKeys),
            ]);
            foreach ($findings as $f) {
                $this->queue->open($f, $run);
            }

            return $run;
        });
    }

    /**
     * Контрольные суммы среза + тождества. Только целые копейки.
     *
     * @param  array<string, mixed>  $input
     * @param  list<array<string, mixed>>  $rowsOut
     * @param  list<array<string, mixed>>  $findings
     * @return array<string, mixed>
     */
    private function totals(array $input, array $rowsOut, array $findings): array
    {
        $channels = [];
        $classes = [];
        foreach ($rowsOut as $i => $r) {
            $kop = (int) ($r['amount_kopecks'] ?? 0);
            $channels[$r['source']]['rows'] = ($channels[$r['source']]['rows'] ?? 0) + 1;
            $channels[$r['source']]['kopecks'] = ($channels[$r['source']]['kopecks'] ?? 0) + $kop;
            $classes[$r['class']]['rows'] = ($classes[$r['class']]['rows'] ?? 0) + 1;
            $classes[$r['class']]['kopecks'] = ($classes[$r['class']]['kopecks'] ?? 0) + $kop;
            $fx = $input['rows'][$i]['foreign_currency'] ?? null;
            if ($fx !== null && ($input['rows'][$i]['foreign_minor'] ?? null) !== null) {
                $channels[$r['source']]['foreign_minor'][$fx] = ($channels[$r['source']]['foreign_minor'][$fx] ?? 0) + (int) $input['rows'][$i]['foreign_minor'];
            }
        }
        ksort($channels);
        ksort($classes);

        $rowsTotal = count($rowsOut);
        $kopTotal = array_sum(array_map(fn ($r) => (int) ($r['amount_kopecks'] ?? 0), $rowsOut));
        $classRows = array_sum(array_column($classes, 'rows'));
        $classKop = array_sum(array_column($classes, 'kopecks'));
        $channelKop = array_sum(array_column($channels, 'kopecks'));

        $ledger = $input['ledger']['totals'] ?? null;
        $ledgerIdentity = $ledger === null
            || ($ledger['student_money_net_kopecks'] === $ledger['allocated_kopecks'] + $ledger['unallocated_residue_kopecks']);

        $findingTypes = [];
        foreach ($findings as $f) {
            $findingTypes[$f['type']] = ($findingTypes[$f['type']] ?? 0) + 1;
        }
        ksort($findingTypes);

        $classDigest = hash('sha256', implode("\n", array_map(fn ($r) => $r['ref'].'='.$r['class'], $rowsOut)));

        return [
            'window' => $input['window'],
            'rows' => $rowsTotal,
            'kopecks' => $kopTotal,
            'channels' => $channels,
            'classes' => $classes,
            'excluded' => $input['excluded'],
            'findings_by_type' => $findingTypes,
            'ledger' => $ledger,
            'payout_packages' => $input['sources']['payout_packages'],
            'classification_digest' => $classDigest,
            'identity' => [
                // Каждая строка окна классифицирована ровно один раз, и копейки не потерялись.
                'rows_classified' => $classRows === $rowsTotal,
                'kopecks_classified' => $classKop === $kopTotal && $channelKop === $kopTotal,
                // P1: деньги студентов = распределено + явный остаток.
                'ledger_student_money' => $ledgerIdentity,
                'ok' => $classRows === $rowsTotal && $classKop === $kopTotal && $channelKop === $kopTotal && $ledgerIdentity,
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $findings
     * @param  list<string>  $newKeys
     * @return array<string, int>
     */
    private function countByType(array $findings, array $newKeys): array
    {
        $new = array_flip($newKeys);
        $out = [];
        $seen = [];
        foreach ($findings as $f) {
            $k = ExceptionQueue::key($f);
            if (isset($new[$k]) && ! isset($seen[$k])) {
                $seen[$k] = true;
                $out[$f['type']] = ($out[$f['type']] ?? 0) + 1;
            }
        }
        ksort($out);

        return $out;
    }
}
