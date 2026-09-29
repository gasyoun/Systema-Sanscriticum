<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;

/** Explicit, read-only payment-period view. No contact data in the result. */
final class ChannelPaymentPeriodReport
{
    public function build(string $from, string $to): array
    {
        $base = Payment::query()->paid()->real();
        $missing = (clone $base)->whereNull('first_paid_at')->count();
        $payments = (clone $base)->where('first_paid_at', '>=', $from.' 00:00:00')
            ->where('first_paid_at', '<', \Carbon\CarbonImmutable::parse($to)->addDay()->startOfDay())
            ->get(['id', 'user_id', 'lead_id', 'amount', 'tariff', 'refund_of_payment_id']);
        $users = User::query()->whereIn('id', $payments->pluck('user_id'))->get(['id', 'lead_id'])->keyBy('id');
        $leads = Lead::query()->whereIn('id', $payments->pluck('lead_id')->merge($users->pluck('lead_id'))->filter())
            ->get(['id', 'utm_source', 'utm_campaign', 'source', 'inferred_source'])->keyBy('id');
        $groups = [];
        foreach ($payments as $payment) {
            if ($payment->tariff === 'salary_payout') {
                continue;
            }
            $lead = $leads->get($payment->lead_id ?? $users->get($payment->user_id)?->lead_id);
            $source = $lead?->source ?: $lead?->utm_source ?: $lead?->inferred_source ?: '(unattributed)';
            $evidence = $lead?->source ? 'declared' : ($lead?->utm_source ? 'tracked' : ($lead?->inferred_source ? 'inferred' : 'unknown'));
            $campaign = $lead?->utm_campaign ?: '(none)';
            $key = json_encode([$source, $campaign, $evidence], JSON_THROW_ON_ERROR);
            $groups[$key] ??= ['source' => $source, 'campaign' => $campaign, 'evidence' => $evidence,
                'payments' => 0, 'receipts_kopecks' => 0, 'refunds_kopecks' => 0, 'net_kopecks' => 0, 'unclassified_kopecks' => 0];
            $row = &$groups[$key];
            $amount = self::kopecks((string) $payment->getRawOriginal('amount'));
            $row['payments']++;
            if ($amount < 0 && $payment->refund_of_payment_id !== null) {
                $row['refunds_kopecks'] += -$amount;
            } elseif ($amount < 0 || $payment->tariff === 'Расход' || $payment->refund_of_payment_id !== null) {
                $row['unclassified_kopecks'] += $amount;
            } else {
                $row['receipts_kopecks'] += $amount;
            }
            $row['net_kopecks'] = $row['receipts_kopecks'] - $row['refunds_kopecks'];
            unset($row);
        }
        $rows = array_values($groups);
        usort($rows, fn ($a, $b) => $b['net_kopecks'] <=> $a['net_kopecks']);
        return ['schema_version' => 1, 'from' => $from, 'to' => $to, 'timezone' => config('app.timezone'),
            'basis' => 'first_paid_at', 'missing_date_count' => $missing, 'rows' => $rows,
            'totals' => array_combine(['receipts_kopecks', 'refunds_kopecks', 'net_kopecks', 'unclassified_kopecks'],
                array_map(fn ($key) => array_sum(array_column($rows, $key)),
                    ['receipts_kopecks', 'refunds_kopecks', 'net_kopecks', 'unclassified_kopecks']))];
    }

    private static function kopecks(string $value): int
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $value, $m)) {
            throw new \UnexpectedValueException('Payment amount is not a two-decimal monetary value.');
        }
        return ($m[1] === '-' ? -1 : 1) * ((int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0'));
    }
}
