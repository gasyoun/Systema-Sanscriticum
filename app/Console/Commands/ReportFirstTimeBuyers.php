<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * H5163 — beginner-acquisition pilot reporting leg: first-ever buyers with
 * attribution, refunds and continuation separated.
 *
 * Read-only. Mirrors the revenue semantics of ReportChannelRoi (H3332):
 * real (non-conditional) payments, tariff outside the non-revenue /
 * pre-purchase sets — the ₽500 tripwire (marathon + guided introduction)
 * IS revenue here. Unlike ReportChannelRoi, refunded payments are kept in
 * scope and shown as their own column (status='refunded' keeps the original
 * positive amount), so net = gross − refunded per channel.
 *
 * Cohort = users whose FIRST-EVER revenue purchase (by first_paid_at,
 * created_at fallback) falls inside --days; a buyer whose only purchase was
 * refunded still counts as a first-time buyer with net 0. Continuation =
 * any later revenue purchase after the first one (later ₽ column = gross
 * minus the first purchase).
 *
 * Buyers without a source resolve to the '—' channel — the ИТОГО row
 * therefore matches finance totals, attribution gaps stay visible instead
 * of silently dropped.
 */
final class ReportFirstTimeBuyers extends Command
{
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    private const PRE_PURCHASE_TARIFFS = ['deposit', 'trial'];

    private const REFUNDED_STATUS = 'refunded';

    /** Цена трипваера (марафон ₽500 + гидированное вводное) — колонка «первый ₽500». */
    private const TRIPWIRE_PRICE = 500;

    protected $signature = 'report:first-time-buyers
        {--days= : Cohort window: users whose FIRST-EVER revenue purchase is within the last N days (default: all time)}
        {--source= : Filter by source key (utm_source / source / inferred_source, e.g. vk)}
        {--by-source : Group by source only (collapse campaigns)}';

    protected $description = 'Пилот новичков: первые в жизни покупатели по каналам, возвраты и продолжения раздельно (read-only)';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $source = trim((string) $this->option('source'));
        $bySource = (bool) $this->option('by-source');

        $leads = Lead::query()
            ->when($source !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('utm_source', $source)->orWhere('source', $source)->orWhere('inferred_source', $source)))
            ->get(['id', 'utm_source', 'utm_campaign', 'source', 'inferred_source'])
            ->keyBy('id');

        $leadIdByUser = User::query()
            ->whereNotNull('lead_id')
            ->pluck('lead_id', 'id');

        $paymentsByUser = Payment::query()
            ->real()
            ->whereIn('status', array_merge(Payment::PAID_STATUSES, [self::REFUNDED_STATUS]))
            ->whereNotIn('tariff', array_merge(self::NON_REVENUE_TARIFFS, self::PRE_PURCHASE_TARIFFS))
            ->get(['user_id', 'tariff', 'amount', 'status', 'first_paid_at', 'created_at'])
            ->groupBy('user_id');

        $windowStart = $days > 0 ? now()->subDays($days)->startOfDay() : null;

        $rows = [];
        $totals = ['buyers' => 0, 'first500' => 0, 'continued' => 0, 'gross' => 0.0, 'refunded' => 0.0, 'later' => 0.0];

        foreach ($paymentsByUser as $userId => $payments) {
            $ordered = $payments->sortBy(fn (Payment $p) => $p->first_paid_at ?? $p->created_at)->values();
            $first = $ordered->first();

            $firstAt = $first->first_paid_at ?? $first->created_at;
            if ($windowStart !== null && $firstAt->lt($windowStart)) {
                continue;
            }

            $lead = $leads->get($leadIdByUser[$userId] ?? null);
            if ($source !== '' && $lead === null) {
                continue;
            }

            $channel = $lead === null || $lead->effectiveSource() === null
                ? '—'
                : ($bySource
                    ? $lead->effectiveSource()
                    : $lead->effectiveSource().' / '.($lead->utm_campaign ?? '—'));

            $gross = (float) $ordered->sum('amount');
            $refunded = (float) $ordered->where('status', self::REFUNDED_STATUS)->sum('amount');
            $firstAmount = (float) $first->amount;

            $row = $rows[$channel] ??= [
                'channel' => $channel, 'buyers' => 0, 'first500' => 0, 'continued' => 0,
                'gross' => 0.0, 'refunded' => 0.0, 'later' => 0.0,
            ];

            $row['buyers']++;
            $row['first500'] += ((int) round($firstAmount) === self::TRIPWIRE_PRICE) ? 1 : 0;
            $row['continued'] += $ordered->count() > 1 ? 1 : 0;
            $row['gross'] += $gross;
            $row['refunded'] += $refunded;
            $row['later'] += max(0.0, $gross - $firstAmount);
            $rows[$channel] = $row;

            $totals['buyers']++;
            $totals['first500'] += ((int) round($firstAmount) === self::TRIPWIRE_PRICE) ? 1 : 0;
            $totals['continued'] += $ordered->count() > 1 ? 1 : 0;
            $totals['gross'] += $gross;
            $totals['refunded'] += $refunded;
            $totals['later'] += max(0.0, $gross - $firstAmount);
        }

        usort($rows, fn (array $a, array $b) => $b['gross'] <=> $a['gross']);

        $this->info('Первые покупатели — '.now()->format('Y-m-d H:i')
            .($days > 0 ? " · первое в жизни покупке за последние {$days} дн." : ' · всё время')
            .($source !== '' ? " · source={$source}" : ''));

        $table = array_map(fn (array $r) => [
            $r['channel'],
            (string) $r['buyers'],
            (string) $r['first500'],
            (string) $r['continued'],
            self::rub($r['gross']),
            self::rub($r['refunded']),
            self::rub($r['gross'] - $r['refunded']),
            self::rub($r['later']),
        ], $rows);
        $table[] = [
            'ИТОГО', (string) $totals['buyers'], (string) $totals['first500'], (string) $totals['continued'],
            self::rub($totals['gross']), self::rub($totals['refunded']),
            self::rub($totals['gross'] - $totals['refunded']), self::rub($totals['later']),
        ];

        $this->table(
            ['канал (источник/кампания)', 'первые', 'первый ₽500', 'продолжили', 'гросс, ₽', 'возвраты, ₽', 'нетто, ₽', 'позже, ₽'],
            $table,
        );

        if ($totals['buyers'] > 0) {
            $this->line(sprintf(
                'caveat: возвраты — строки со статусом refunded (сумма оригинала); продолжение = вторая и далее покупки после первой.',
            ));
        }

        return self::SUCCESS;
    }

    private static function rub(float $value): string
    {
        return number_format($value, 0, ',', ' ');
    }
}
