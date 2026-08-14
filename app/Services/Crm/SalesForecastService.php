<?php

declare(strict_types=1);

namespace App\Services\Crm;

use App\Models\Deal;
use App\Models\DealStage;
use App\Models\DealTransition;
use App\Models\FollowUpTask;
use App\Services\Reports\OrderPaymentConversionService;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CRM Wave 3 (H2485) — pipeline-weighted forecast over open Deals.
 *
 * Reads money. Never writes Payment, tariff or access. Actuals come from
 * {@see OrderPaymentConversionService::qualifyingPaidRevenue()} so the
 * conversion denominator cannot fork.
 */
final class SalesForecastService
{
    public function __construct(
        private readonly OrderPaymentConversionService $conversion,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Carbon $asOf = null, ?int $onlyManagerId = null): array
    {
        $asOf = ($asOf ?? now())->copy();
        $horizon = max(1, (int) config('crm_forecast.horizon_days', 30));
        $pipeline = $this->openPipeline($asOf, $onlyManagerId);
        $prevFrom = $asOf->copy()->subDays($horizon);
        $actuals = $this->conversion->qualifyingPaidRevenue($prevFrom, $asOf, $onlyManagerId);

        return [
            'as_of' => $asOf->toDateTimeString(),
            'horizon_days' => $horizon,
            'manager_scope' => $onlyManagerId !== null,
            'manager_id' => $onlyManagerId,
            'assumptions' => $this->assumptions(),
            'attribution' => [
                'pipeline' => 'deals.assigned_to',
                'actuals' => 'payments.created_by_user_id',
                'note' => 'Open-pipeline owner is the deal assignee. Actual revenue uses the manager-sales denominator (who created the Payment row). They can diverge.',
            ],
            'pipeline' => $pipeline,
            'actuals_previous_horizon' => $actuals,
            'forecast_vs_actual' => [
                'comparable' => false,
                'reason' => 'Current weighted forecast is for the NEXT '.$horizon.' days. Actuals shown are the PREVIOUS '.$horizon.' days. Comparable error lives only in backtest.',
                'previous_actual_amount' => $actuals['paid_amount'],
                'current_forecast_mid' => $pipeline['weighted_mid'],
                'current_forecast_low' => $pipeline['weighted_low'],
                'current_forecast_high' => $pipeline['weighted_high'],
            ],
            'backtest' => $this->backtest($asOf, $onlyManagerId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function assumptions(): array
    {
        return [
            'horizon_days' => (int) config('crm_forecast.horizon_days', 30),
            'aging_windows_days' => array_values(array_map('intval', (array) config('crm_forecast.aging_windows_days', [7, 14, 30]))),
            'backtest_windows_days' => array_values(array_map('intval', (array) config('crm_forecast.backtest_windows_days', [30, 90]))),
            'history_available_from' => (string) config('crm_forecast.history_available_from', '2026-07-25'),
            'stage_probabilities' => $this->probabilityTable(),
            'actuals_denominator' => 'Payment is_conditional=false AND tariff NOT IN conversion.excluded_tariffs AND status IN (paid,success); cohort by payments.created_at',
        ];
    }

    /**
     * Open pipeline as of $asOf. Live (asOf ≈ now) uses Deal::open(). Historical
     * asOf reconstructs stage from DealTransition; deals whose stage cannot be
     * recovered are counted as unavailable and excluded from the weighted sum.
     *
     * @return array<string, mixed>
     */
    public function openPipeline(Carbon $asOf, ?int $onlyManagerId = null): array
    {
        $live = $asOf->diffInSeconds(now(), true) < 2;
        $deals = $live
            ? $this->liveOpenDeals($onlyManagerId)
            : $this->dealsExistingAt($asOf, $onlyManagerId);

        $stagesById = DealStage::query()->ordered()->get()->keyBy('id');
        $rows = [];
        $unavailable = 0;
        $openAmount = Money::zero();
        $weighted = ['mid' => Money::zero(), 'low' => Money::zero(), 'high' => Money::zero()];

        foreach ($deals as $deal) {
            $resolved = $live
                ? ['stage_id' => (int) $deal->stage_id, 'status' => 'available']
                : $this->stageAt($deal, $asOf);

            if ($resolved['status'] !== 'available' || $resolved['stage_id'] === null) {
                $unavailable++;

                continue;
            }

            $stage = $stagesById->get($resolved['stage_id']);
            if (! $stage instanceof DealStage || $stage->isFinal()) {
                continue;
            }

            $band = $this->bandFor((string) $stage->key);
            if ($band === null) {
                $unavailable++;

                continue;
            }

            $amount = Money::of((float) $deal->amount);
            $openAmount = $openAmount->plus($amount);
            foreach (['mid', 'low', 'high'] as $edge) {
                $weighted[$edge] = $weighted[$edge]->plus($amount->times($band[$edge]));
            }

            $enteredAt = $this->stageEnteredAt($deal, $resolved['stage_id'], $asOf);
            $ageDays = max(0, (int) $enteredAt->diffInDays($asOf));

            $rows[] = [
                'deal_id' => (int) $deal->id,
                'amount' => $amount->value(),
                'stage_key' => (string) $stage->key,
                'stage_name' => (string) $stage->name,
                'assigned_to' => $deal->assigned_to === null ? null : (int) $deal->assigned_to,
                'assignee' => $deal->assignee?->name ?? 'Без менеджера',
                'age_days' => $ageDays,
                'has_open_task' => $this->hasOpenTask($deal),
                'probability' => $band,
            ];
        }

        return [
            'as_of' => $asOf->toDateTimeString(),
            'live' => $live,
            'deal_count' => count($rows),
            'unavailable_deal_count' => $unavailable,
            'open_amount' => $openAmount->value(),
            'weighted_mid' => $weighted['mid']->value(),
            'weighted_low' => $weighted['low']->value(),
            'weighted_high' => $weighted['high']->value(),
            'by_stage' => $this->groupByStage($rows),
            'aging' => $this->groupAging($rows),
            'next_action' => $this->nextActionCoverage($rows),
            'by_manager' => $this->groupByManager($rows),
            'rows' => $rows,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function backtest(Carbon $asOf, ?int $onlyManagerId = null): array
    {
        $windows = array_values(array_map('intval', (array) config('crm_forecast.backtest_windows_days', [30, 90])));
        $fromFloor = Carbon::parse((string) config('crm_forecast.history_available_from', '2026-07-25'))->startOfDay();
        $out = [];

        foreach ($windows as $days) {
            $days = max(1, $days);
            $t0 = $asOf->copy()->subDays($days);
            $t1 = $asOf->copy();

            if ($t0->lt($fromFloor)) {
                $out[] = [
                    'window_days' => $days,
                    'from' => $t0->toDateTimeString(),
                    'to' => $t1->toDateTimeString(),
                    'status' => 'unavailable',
                    'reason' => 'Deal journal does not cover this period (history_available_from='.$fromFloor->toDateString().'). No snapshot was fabricated.',
                    'forecast_mid' => null,
                    'forecast_low' => null,
                    'forecast_high' => null,
                    'actual_amount' => null,
                    'error' => null,
                    'unavailable_deal_count' => null,
                ];

                continue;
            }

            $pipeline = $this->openPipeline($t0, $onlyManagerId);
            $actuals = $this->conversion->qualifyingPaidRevenue($t0, $t1, $onlyManagerId);
            $status = $pipeline['unavailable_deal_count'] > 0
                ? ($pipeline['deal_count'] > 0 ? 'partial' : 'unavailable')
                : 'available';

            $forecast = $pipeline['weighted_mid'];
            $actual = $actuals['paid_amount'];
            $error = $status === 'unavailable' ? null : Money::round($actual - $forecast);

            $out[] = [
                'window_days' => $days,
                'from' => $t0->toDateTimeString(),
                'to' => $t1->toDateTimeString(),
                'status' => $status,
                'reason' => $status === 'available'
                    ? null
                    : ($status === 'partial'
                        ? $pipeline['unavailable_deal_count'].' open deal(s) had no reconstructable stage at window start; they were excluded rather than invented.'
                        : 'Stage at window start could not be recovered from DealTransition for the candidate deals. No snapshot was fabricated.'),
                'forecast_mid' => $status === 'unavailable' ? null : $forecast,
                'forecast_low' => $status === 'unavailable' ? null : $pipeline['weighted_low'],
                'forecast_high' => $status === 'unavailable' ? null : $pipeline['weighted_high'],
                'open_amount' => $status === 'unavailable' ? null : $pipeline['open_amount'],
                'deal_count' => $pipeline['deal_count'],
                'unavailable_deal_count' => $pipeline['unavailable_deal_count'],
                'actual_amount' => $actual,
                'actual_paid_count' => $actuals['paid_count'],
                'error' => $error,
            ];
        }

        return $out;
    }

    /**
     * @return Collection<int, Deal>
     */
    private function liveOpenDeals(?int $onlyManagerId): Collection
    {
        return Deal::query()
            ->open()
            ->with(['stage', 'assignee', 'followUpTasks', 'transitions'])
            ->when($onlyManagerId !== null, fn ($q) => $q->where('assigned_to', $onlyManagerId))
            ->get();
    }

    /**
     * @return Collection<int, Deal>
     */
    private function dealsExistingAt(Carbon $asOf, ?int $onlyManagerId): Collection
    {
        return Deal::query()
            ->where('created_at', '<=', $asOf)
            ->where(function ($q) use ($asOf): void {
                $q->whereNull('closed_at')->orWhere('closed_at', '>', $asOf);
            })
            ->with(['stage', 'assignee', 'followUpTasks', 'transitions'])
            ->when($onlyManagerId !== null, fn ($q) => $q->where('assigned_to', $onlyManagerId))
            ->get();
    }

    /**
     * Reconstruct stage_id at $asOf from the append-only journal.
     *
     * @return array{stage_id: int|null, status: 'available'|'unavailable'}
     */
    private function stageAt(Deal $deal, Carbon $asOf): array
    {
        $transitions = $deal->transitions
            ->sortBy(fn (DealTransition $t) => $t->created_at?->timestamp ?? 0)
            ->values();

        $lastAtOrBefore = $transitions->last(
            fn (DealTransition $t): bool => $t->created_at !== null && $t->created_at->lte($asOf)
        );
        if ($lastAtOrBefore instanceof DealTransition && $lastAtOrBefore->to_stage_id !== null) {
            return ['stage_id' => (int) $lastAtOrBefore->to_stage_id, 'status' => 'available'];
        }

        $firstAfter = $transitions->first(
            fn (DealTransition $t): bool => $t->created_at !== null && $t->created_at->gt($asOf)
        );
        if ($firstAfter instanceof DealTransition && $firstAfter->from_stage_id !== null) {
            return ['stage_id' => (int) $firstAfter->from_stage_id, 'status' => 'available'];
        }

        if ($transitions->isEmpty() && $deal->stage && ! $deal->stage->isFinal()) {
            return ['stage_id' => (int) $deal->stage_id, 'status' => 'available'];
        }

        return ['stage_id' => null, 'status' => 'unavailable'];
    }

    private function stageEnteredAt(Deal $deal, int $stageId, Carbon $asOf): Carbon
    {
        $hit = $deal->transitions
            ->filter(fn (DealTransition $t): bool => (int) $t->to_stage_id === $stageId
                && $t->created_at !== null
                && $t->created_at->lte($asOf))
            ->sortByDesc(fn (DealTransition $t) => $t->created_at?->timestamp ?? 0)
            ->first();

        if ($hit instanceof DealTransition && $hit->created_at !== null) {
            return $hit->created_at->copy();
        }

        return ($deal->created_at ?? $asOf)->copy();
    }

    private function hasOpenTask(Deal $deal): bool
    {
        return $deal->followUpTasks
            ->contains(fn (FollowUpTask $task): bool => $task->done_at === null);
    }

    /**
     * @return array{mid: float, low: float, high: float}|null
     */
    private function bandFor(string $key): ?array
    {
        $raw = config('crm_forecast.stage_probabilities.'.$key);
        if (! is_array($raw) || ! isset($raw['mid'])) {
            return null;
        }

        return [
            'mid' => (float) $raw['mid'],
            'low' => (float) ($raw['low'] ?? $raw['mid']),
            'high' => (float) ($raw['high'] ?? $raw['mid']),
        ];
    }

    /**
     * @return array<string, array{mid: float, low: float, high: float}>
     */
    private function probabilityTable(): array
    {
        $out = [];
        foreach ((array) config('crm_forecast.stage_probabilities', []) as $key => $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $out[(string) $key] = [
                'mid' => (float) ($raw['mid'] ?? 0),
                'low' => (float) ($raw['low'] ?? $raw['mid'] ?? 0),
                'high' => (float) ($raw['high'] ?? $raw['mid'] ?? 0),
            ];
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupByStage(array $rows): array
    {
        return collect($rows)
            ->groupBy('stage_key')
            ->map(function (Collection $group, string $key): array {
                $amount = Money::zero();
                $weighted = Money::zero();
                foreach ($group as $row) {
                    $amount = $amount->plus(Money::of((float) $row['amount']));
                    $weighted = $weighted->plus(Money::of((float) $row['amount'])->times((float) $row['probability']['mid']));
                }
                $first = $group->first();

                return [
                    'stage_key' => $key,
                    'stage_name' => (string) ($first['stage_name'] ?? $key),
                    'deal_count' => $group->count(),
                    'open_amount' => $amount->value(),
                    'probability_mid' => (float) ($first['probability']['mid'] ?? 0),
                    'probability_low' => (float) ($first['probability']['low'] ?? 0),
                    'probability_high' => (float) ($first['probability']['high'] ?? 0),
                    'weighted_mid' => $weighted->value(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupAging(array $rows): array
    {
        $windows = array_values(array_map('intval', (array) config('crm_forecast.aging_windows_days', [7, 14, 30])));
        sort($windows);
        $labels = [];
        $prev = 0;
        foreach ($windows as $w) {
            $labels[] = ['label' => $prev.'–'.$w, 'max' => $w];
            $prev = $w + 1;
        }
        $labels[] = ['label' => $prev.'+', 'max' => null];

        $buckets = [];
        foreach ($labels as $i => $meta) {
            $buckets[$i] = [
                'bucket' => $meta['label'],
                'deal_count' => 0,
                'open_amount' => Money::zero(),
            ];
        }

        foreach ($rows as $row) {
            $age = (int) $row['age_days'];
            $idx = count($labels) - 1;
            $cursor = 0;
            foreach ($windows as $i => $w) {
                if ($age <= $w) {
                    $idx = $i;
                    break;
                }
                $cursor = $i;
                unset($cursor);
            }
            $buckets[$idx]['deal_count']++;
            $buckets[$idx]['open_amount'] = $buckets[$idx]['open_amount']->plus(Money::of((float) $row['amount']));
        }

        return array_map(static function (array $b): array {
            return [
                'bucket' => $b['bucket'],
                'deal_count' => $b['deal_count'],
                'open_amount' => $b['open_amount']->value(),
            ];
        }, $buckets);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{open_deals: int, with_open_task: int, coverage_pct: float|null, overdue: int}
     */
    private function nextActionCoverage(array $rows): array
    {
        $open = count($rows);
        $with = collect($rows)->where('has_open_task', true)->count();

        return [
            'open_deals' => $open,
            'with_open_task' => $with,
            'coverage_pct' => $open > 0 ? round($with / $open * 100, 1) : null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function groupByManager(array $rows): array
    {
        return collect($rows)
            ->groupBy('assignee')
            ->map(function (Collection $group, string $name): array {
                $amount = Money::zero();
                $weighted = Money::zero();
                foreach ($group as $row) {
                    $amount = $amount->plus(Money::of((float) $row['amount']));
                    $weighted = $weighted->plus(Money::of((float) $row['amount'])->times((float) $row['probability']['mid']));
                }

                return [
                    'manager' => $name,
                    'deal_count' => $group->count(),
                    'open_amount' => $amount->value(),
                    'weighted_mid' => $weighted->value(),
                ];
            })
            ->sortByDesc('open_amount')
            ->values()
            ->all();
    }
}
