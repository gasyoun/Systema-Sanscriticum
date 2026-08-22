<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * H3332 — unit-economics layer, lead→revenue leg: per-channel ROI over
 * UTM-tagged leads (MONETIZATION_PLAN_2026H2 §7, idea #5 of growth-ideas).
 *
 * Read-only. Joins leads (UTM) → users (users.lead_id) → payments, counting
 * every real ruble the acquired users brought (paid, non-conditional, tariff
 * outside non-revenue/pre-purchase sets) — the tripwire ₽500 INCLUDED here,
 * unlike the marathon A/B report where it is a separate column.
 *
 * Feeds: VK-test stop rules (H3333 §4), pricing checkpoint calibration,
 * discount-stack/installment caps (@DECIDE after this layer).
 */
final class ReportChannelRoi extends Command
{
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    private const PRE_PURCHASE_TARIFFS = ['deposit', 'trial'];

    protected $signature = 'report:channel-roi
        {--days= : Restrict leads created within the last N days (default: all time)}
        {--source= : Filter by utm_source (e.g. vk)}';

    protected $description = 'Юнит-слой: лиды (UTM) → пользователи → выручка по каналам, ROI витрина';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $source = trim((string) $this->option('source'));

        $leads = Lead::query()
            ->whereNotNull('utm_source')
            ->when($source !== '', fn ($q) => $q->where('utm_source', $source))
            ->when($days > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)->startOfDay()))
            ->get(['id', 'utm_source', 'utm_campaign', 'created_at']);

        $usersByLead = User::query()
            ->whereNotNull('lead_id')
            ->pluck('id', 'lead_id');

        $revenueByUser = Payment::query()
            ->paid()
            ->real()
            ->whereNotIn('tariff', array_merge(self::NON_REVENUE_TARIFFS, self::PRE_PURCHASE_TARIFFS))
            ->whereNotNull('first_paid_at')
            ->get(['user_id', 'amount'])
            ->groupBy('user_id')
            ->map(fn ($g) => (float) $g->sum('amount'));

        $groups = $leads
            ->mapToGroups(fn ($l) => [$l->utm_source.' / '.($l->utm_campaign ?? '—') => $l]);

        $rows = [];
        foreach ($groups as $channel => $channelLeads) {
            $userIds = $channelLeads
                ->map(fn ($l) => $usersByLead[$l->id] ?? null)
                ->filter()
                ->unique()
                ->values();

            $payers = $userIds->filter(fn ($uid) => $revenueByUser->has($uid));
            $revenue = $payers->sum(fn ($uid) => $revenueByUser[$uid]);

            $rows[] = [
                'channel' => $channel,
                'leads' => $channelLeads->count(),
                'users' => $userIds->count(),
                'payers' => $payers->count(),
                'revenue, ₽' => number_format($revenue, 0, ',', ' '),
                'rev/lead, ₽' => $channelLeads->count() > 0
                    ? number_format($revenue / $channelLeads->count(), 0, ',', ' ')
                    : '—',
                'first lead' => $channelLeads->min('created_at')?->toDateString() ?? '—',
            ];
        }

        usort($rows, fn ($a, $b) => strcmp($b['revenue, ₽'], $a['revenue, ₽']));

        $this->info('Channel ROI — '.now()->format('Y-m-d H:i')
            .($days > 0 ? " · leads created last {$days} d" : ' · all time')
            .($source !== '' ? " · source={$source}" : ''));

        $this->table(
            ['channel (source/campaign)', 'leads', 'users', 'payers', 'revenue, ₽', 'rev/lead, ₽', 'first lead'],
            $rows,
        );

        $knownEmailShare = $leads->count() > 0
            ? round(100 * $usersByLead->count() / max(1, $leads->count()), 1)
            : null;
        if ($knownEmailShare !== null) {
            $this->line(sprintf(
                'caveat: %d/%d (%s%%) leads link to users — attribution gaps before H324 magic-link remain in history.',
                $usersByLead->count(), $leads->count(), number_format((float) $knownEmailShare, 1, ',', ' '),
            ));
        }

        return self::SUCCESS;
    }
}
