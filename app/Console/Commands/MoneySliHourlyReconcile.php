<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Support\MoneySli\MoneySliAlerter;
use App\Support\Observability\ProbeOutcome;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * H4672 — hourly, read-only money-axis reconciliation. Writes nothing to
 * payments/payment_webhook_events; only reads and, on a breach, pages.
 *
 * Two checks:
 *  1. Webhook success-rate over the window (payment_webhook_events.decision).
 *  2. Silent-grant (H2085 class): a real payment marked paid, with a course
 *     that needs groups, whose buyer is NOT in any of the course's groups —
 *     "оплатил без доступа". This is what H2085/H2304's fail-closed guard
 *     exists to prevent; this check is the SLI that proves it's still working
 *     in production, not just in the test suite.
 *
 * "Дельты балансов" from the H4672 mission is deliberately scoped down here
 * to paid-payment volume in the window (an aliveness signal), NOT a full
 * wallet/referral/deposit ledger reconciliation — that would be its own
 * multi-hour handoff. Flagged in the PR as a known scope limitation.
 */
class MoneySliHourlyReconcile extends Command
{
    protected $signature = 'money:sli-hourly-reconcile
        {--dry : Прогнать без TG/heartbeat}
        {--force-alert : Игнорировать TG-cooldown}';

    protected $description = 'H4672: почасовая read-only сверка денежной оси (webhook success-rate + silent-grant)';

    public function handle(MoneySliAlerter $alerter): int
    {
        if (! config('features.money_sli_hourly_reconcile')) {
            // H5061: шов не вооружён — громкий машинночитаемый маркер.
            // TSV при этом НЕ пишем: часовой каденс превратил бы метрику в спам.
            $this->comment('features.money_sli_hourly_reconcile OFF — команда no-op до MONEY_SLI_HOURLY_RECONCILE=true.');
            Log::warning('money_sli: hourly-reconcile не вооружён (features.money_sli_hourly_reconcile=false) — статус not_supported', [
                'check' => 'hourly_reconcile',
                'state' => ProbeOutcome::NOT_SUPPORTED,
            ]);

            return self::SUCCESS;
        }

        $dry = (bool) $this->option('dry');
        $force = (bool) $this->option('force-alert');
        $cfg = config('money_sli.reconcile');

        $windowSince = now()->subHours(max(1, (int) $cfg['window_hours']));
        $webhookEvents = PaymentWebhookEvent::where('created_at', '>=', $windowSince)->get(['decision']);
        $total = $webhookEvents->count();
        $applied = $webhookEvents->where('decision', PaymentWebhookEvent::DECISION_APPLIED)->count();
        $successRate = $total > 0 ? $applied / $total : 1.0;
        $minSample = max(1, (int) $cfg['min_sample']);
        $rateFloor = (float) $cfg['webhook_success_rate_floor'];
        $rateBreach = $total >= $minSample && $successRate < $rateFloor;

        $lookbackSince = now()->subHours(max(1, (int) $cfg['silent_grant_lookback_hours']));
        $silentGrants = $this->findSilentGrants($lookbackSince);
        $paidVolume = Payment::where('status', 'paid')->where('created_at', '>=', $windowSince)->count();

        $healthy = ! $rateBreach && $silentGrants->isEmpty();

        $alerter->appendTsvRow([
            'date' => now()->toDateString(),
            'time_utc' => now()->utc()->toTimeString(),
            'check' => 'hourly_reconcile',
            'status' => $healthy ? 'ok' : 'fail',
            'webhook_total' => $total,
            'webhook_applied' => $applied,
            'webhook_success_rate' => round($successRate, 3),
            'silent_grants' => $silentGrants->count(),
            'paid_volume_window' => $paidVolume,
            'notes' => $healthy ? '' : 'see TG',
        ]);

        $failSummary = $rateBreach
            ? "webhook success-rate {$successRate} < {$rateFloor} ({$applied}/{$total})"
            : ($silentGrants->isEmpty() ? '' : 'silent-grant: '.$silentGrants->implode('id', ', '));

        $alerter->heartbeat(
            (string) config('money_sli.hourly_ping_url', ''),
            $healthy,
            $failSummary,
            $dry,
        );

        if ($healthy) {
            $alerter->recovered('hourly_reconcile');
            $this->info("✅ hourly-reconcile зеленый (webhook {$applied}/{$total}, {$silentGrants->count()} silent-grant, {$paidVolume} paid в окне).");

            return self::SUCCESS;
        }

        $lines = [];
        if ($rateBreach) {
            $lines[] = "webhook success-rate {$applied}/{$total} = ".round($successRate * 100, 1).'% (порог '.round($rateFloor * 100, 1).'%)';
        }
        if ($silentGrants->isNotEmpty()) {
            $lines[] = 'H2085 silent-grant (paid без доступа), payment id: '.$silentGrants->implode('id', ', ');
            $lines[] = 'Смотри Payment::grantAccess(), features.grant_access_fail_closed, money-access-core-manual.md';
        }

        $alerter->alert(
            'hourly_reconcile',
            'Money-axis: почасовая сверка нашла проблему',
            $lines,
            $force,
            $dry,
        );

        $this->error('❌ hourly-reconcile: '.implode(' | ', $lines));

        return self::FAILURE;
    }

    /**
     * @return Collection<int, Payment>
     */
    private function findSilentGrants(Carbon $since): Collection
    {
        return Payment::query()
            ->where('status', 'paid')
            ->whereNotNull('course_id')
            ->where('created_at', '>=', $since)
            ->whereNotIn('tariff', ['deposit', 'trial', 'marathon_paid', 'donation', 'gift', 'salary_payout', 'Расход'])
            ->with(['user', 'course.groups'])
            ->get()
            ->filter(function (Payment $payment): bool {
                if (! $payment->user || ! $payment->course) {
                    return false;
                }
                $groupIds = $payment->course->groups->pluck('id');
                if ($groupIds->isEmpty()) {
                    // No groups on the course at all — that's grant_access_fail_closed's
                    // job to catch (throw-on-write), not this read-only reconcile's.
                    return false;
                }

                return ! $payment->user->groups()->whereIn('groups.id', $groupIds)->exists();
            })
            ->values();
    }
}
