<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Payment;
use App\Models\User;
use App\Support\Roles;
use Filament\Notifications\Notification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

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
 *
 * H5021: channel = Lead::effectiveSource() (source руками > inferred_source
 * ночной разметки > utm_source), поэтому лиды без UTM, но с выведенным
 * источником, тоже попадают в срез. --by-source схлопывает кампании,
 * --digest шлёт сводку получателям KPI-дайджеста (database notification) —
 * так отчёт приезжает в понедельничный дайджест MG. По-прежнему read-only.
 */
final class ReportChannelRoi extends Command
{
    private const NON_REVENUE_TARIFFS = ['Расход', 'salary_payout'];

    private const PRE_PURCHASE_TARIFFS = ['deposit', 'trial'];

    protected $signature = 'report:channel-roi
        {--days= : Restrict leads created within the last N days (default: all time)}
        {--source= : Filter by source key (utm_source / source / inferred_source, e.g. vk)}
        {--by-source : Group by source only (collapse campaigns)}
        {--digest : Send the per-channel summary to KPI-digest recipients (database notification)}';

    protected $description = 'Юнит-слой: лиды (UTM) → пользователи → выручка по каналам, ROI витрина';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $source = trim((string) $this->option('source'));

        $bySource = (bool) $this->option('by-source');

        $leads = Lead::query()
            ->where(fn (Builder $q) => $q
                ->whereNotNull('utm_source')->orWhereNotNull('source')->orWhereNotNull('inferred_source'))
            ->when($source !== '', fn ($q) => $q->where(fn (Builder $w) => $w
                ->where('utm_source', $source)->orWhere('source', $source)->orWhere('inferred_source', $source)))
            ->when($days > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($days)->startOfDay()))
            ->get(['id', 'utm_source', 'utm_campaign', 'source', 'inferred_source', 'created_at'])
            ->filter(fn (Lead $l) => $l->effectiveSource() !== null)
            ->values();

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
            ->mapToGroups(fn ($l) => [
                $bySource
                    ? $l->effectiveSource()
                    : $l->effectiveSource().' / '.($l->utm_campaign ?? '—') => $l,
            ]);

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

        if ($this->option('digest')) {
            $this->sendDigest($rows, $days);
        }

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

    /**
     * Понедельничный дайджест (H5021): те же получатели, что у finance:kpi-digest.
     *
     * @param  list<array<string,int|string>>  $rows
     */
    private function sendDigest(array $rows, int $days): void
    {
        $recipients = User::query()
            ->whereIn('role', [Roles::SUPER_ADMIN, Roles::ADMIN, Roles::ACCOUNTANT])
            ->get();

        if ($recipients->isEmpty()) {
            $this->error('Нет получателей (super_admin/admin/accountant) — дайджест каналов не отправлен.');

            return;
        }

        $lines = array_map(
            fn (array $r) => sprintf(
                '%s: лидов %d · юзеров %d · платили %d · %s ₽',
                $r['channel'], $r['leads'], $r['users'], $r['payers'], $r['revenue, ₽'],
            ),
            array_slice($rows, 0, 12),
        );
        $body = $lines === []
            ? 'За период нет лидов с источником — разметке нечего атрибутировать.'
            : implode("\n", $lines);

        foreach ($recipients as $recipient) {
            Notification::make()
                ->title('Каналы: лиды → выручка'.($days > 0 ? " (последние {$days} дн.)" : ' (всё время)'))
                ->body($body)
                ->info()
                ->sendToDatabase($recipient);
        }

        $this->info('Дайджест каналов отправлен получателям: '.$recipients->count());
    }
}
