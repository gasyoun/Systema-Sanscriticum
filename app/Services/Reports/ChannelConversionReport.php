<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Тикет A1, шаг 3: связка lead → user → payment — конверсия по каналам (UTM
 * source) среди новых регистраций за месяц. «direct» — визиты без метки
 * (прямой заход/старые аккаунты до атрибуции).
 */
class ChannelConversionReport
{
    /**
     * @return list<array{channel: string, users: int, matched_to_lead: int, paid_users: int, revenue: float, conversion: float}>
     */
    public static function forMonth(?Carbon $month = null): array
    {
        $month ??= now();
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $channels = User::query()
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw("COALESCE(utm_source, 'direct') as channel")
            ->distinct()
            ->pluck('channel');

        $rows = [];
        foreach ($channels as $channel) {
            $usersQuery = User::query()->whereBetween('created_at', [$start, $end]);
            $usersQuery = $channel === 'direct'
                ? $usersQuery->whereNull('utm_source')
                : $usersQuery->where('utm_source', $channel);

            $userIds = (clone $usersQuery)->pluck('id');
            $usersCount = $userIds->count();
            if ($usersCount === 0) {
                continue;
            }

            $matchedToLead = (clone $usersQuery)->whereNotNull('lead_id')->count();

            $paidUsers = Payment::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->distinct('user_id')
                ->count('user_id');

            $revenue = (float) Payment::query()
                ->whereIn('user_id', $userIds)
                ->whereIn('status', Payment::PAID_STATUSES)
                ->sum('amount');

            $rows[] = [
                'channel' => $channel,
                'users' => $usersCount,
                'matched_to_lead' => $matchedToLead,
                'paid_users' => $paidUsers,
                'revenue' => $revenue,
                'conversion' => round($paidUsers / $usersCount * 100, 1),
            ];
        }

        usort($rows, fn ($a, $b) => $b['users'] <=> $a['users']);

        return $rows;
    }
}
