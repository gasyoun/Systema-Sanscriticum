<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\OpenrouterBalanceSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * MG 24-08-2026: know the OpenRouter balance, project the run-out date from
 * our own daily snapshots (the API exposes only lifetime totals), and size a
 * yearly top-up request two weeks before the projected zero.
 */
final class OpenrouterBalanceForecast
{
    /**
     * Fetch lifetime totals and store today's snapshot. Idempotent per day.
     *
     * @return array{ok: bool, total_credits: ?float, total_usage: ?float, remaining: ?float, note: ?string}
     */
    public function fetchAndStore(): array
    {
        $fetched = $this->fetch();
        if (! $fetched['ok'] || ! $this->persistable($fetched)) {
            return $fetched;
        }

        // Key by Carbon start-of-day: a plain date string would not match the
        // datetime-formatted value the date cast writes back on sqlite.
        OpenrouterBalanceSnapshot::updateOrCreate(
            ['snapshot_date' => CarbonImmutable::now()->startOfDay()],
            ['total_credits' => $fetched['total_credits'], 'total_usage' => $fetched['total_usage']],
        );

        return $fetched;
    }

    /**
     * Same fetch, never persists (--dry).
     *
     * @return array{ok: bool, total_credits: ?float, total_usage: ?float, remaining: ?float, note: ?string}
     */
    public function fetch(): array
    {
        $key = (string) config('openrouter.key', '');
        $base = (string) config('openrouter.base_url', '');

        if ($key === '' || $base === '') {
            return ['ok' => false, 'total_credits' => null, 'total_usage' => null, 'remaining' => null, 'note' => 'OPENROUTER_API_KEY пуст — skip-soft'];
        }

        try {
            $response = Http::timeout(max(2, (int) config('openrouter.timeout', 10)))
                ->withHeaders(['Authorization' => 'Bearer '.$key, 'Accept' => 'application/json'])
                ->get($base.'/credits');
        } catch (Throwable $e) {
            Log::info('openrouter balance skip-soft', ['error' => $e->getMessage()]);

            return ['ok' => false, 'total_credits' => null, 'total_usage' => null, 'remaining' => null, 'note' => $e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'total_credits' => null, 'total_usage' => null, 'remaining' => null, 'note' => 'HTTP '.$response->status()];
        }

        $payload = $response->json('data') ?? [];
        $credits = isset($payload['total_credits']) ? round((float) $payload['total_credits'], 2) : null;
        $usage = isset($payload['total_usage']) ? round((float) $payload['total_usage'], 2) : null;
        if ($credits === null || $usage === null) {
            return ['ok' => false, 'total_credits' => null, 'total_usage' => null, 'remaining' => null, 'note' => 'unexpected payload'];
        }

        return ['ok' => true, 'total_credits' => $credits, 'total_usage' => $usage, 'remaining' => round($credits - $usage, 2), 'note' => null];
    }

    /**
     * @param  array{ok: bool, total_credits: ?float, total_usage: ?float, remaining: ?float, note: ?string}  $fetched
     */
    private function persistable(array $fetched): bool
    {
        return $fetched['total_credits'] !== null && $fetched['total_usage'] !== null;
    }

    /**
     * Burn-rate forecast over the configured lookback window.
     *
     * @return array{daily_avg: ?float, baseline_days: int, days_left: ?int, runout_date: ?string}
     */
    public function forecast(): array
    {
        $since = CarbonImmutable::now()->subDays((int) config('openrouter.lookback_days', 28));
        $rows = OpenrouterBalanceSnapshot::query()
            ->where('snapshot_date', '>=', $since->toDateString())
            ->orderBy('snapshot_date')
            ->get();

        if ($rows->count() < 2) {
            return ['daily_avg' => null, 'baseline_days' => $rows->count(), 'days_left' => null, 'runout_date' => null];
        }

        $first = $rows->first();
        $last = $rows->last();
        $spanDays = max(1, $first->snapshot_date->diffInDays($last->snapshot_date));
        if ($spanDays < (int) config('openrouter.min_baseline_days', 7)) {
            return ['daily_avg' => null, 'baseline_days' => $spanDays, 'days_left' => null, 'runout_date' => null];
        }

        $dailyAvg = round(((float) $last->total_usage - (float) $first->total_usage) / $spanDays, 4);
        if ($dailyAvg <= 0) {
            return ['daily_avg' => $dailyAvg, 'baseline_days' => $spanDays, 'days_left' => null, 'runout_date' => null];
        }

        $remaining = max(0.0, (float) $last->total_credits - (float) $last->total_usage);
        $daysLeft = (int) floor($remaining / $dailyAvg);

        return [
            'daily_avg' => $dailyAvg,
            'baseline_days' => $spanDays,
            'days_left' => $daysLeft,
            'runout_date' => CarbonImmutable::now()->addDays($daysLeft)->toDateString(),
        ];
    }

    /**
     * Yearly top-up sized from the burn rate with the safety factor,
     * rounded up to the configured multiple.
     */
    public function suggestedTopup(float $dailyAvg): float
    {
        $raw = $dailyAvg
            * (int) config('openrouter.horizon_days', 365)
            * (float) config('openrouter.safety_factor', 1.25);
        $roundTo = max(1, (int) config('openrouter.topup_round_to', 10));

        return (float) (ceil($raw / $roundTo) * $roundTo);
    }
}
