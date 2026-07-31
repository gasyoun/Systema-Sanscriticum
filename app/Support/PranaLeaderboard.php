<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarathonEnrollment;
use App\Models\PranaTransaction;
use App\Models\User;
use App\Services\Prana\PranaSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Таблица лидеров по пране — геймификация поверх рангов.
 *
 * Периоды (Memrise-аналог Week / Month / All Time):
 * - `week`  — сумма начислений (amount > 0) с начала текущей календарной недели (пн)
 * - `month` — сумма начислений с 1-го числа текущего месяца
 * - `all`   — users.lifetime_prana (как раньше; не уменьшается тратами)
 *
 * Имена маскируются (имя + инициал). Считаются только студенты (не админы)
 * с score > 0.
 *
 * H447 §5 — leaderboard A/B (Uprava/docs/SARASWATI_TRAINERS_2026.md §5).
 * Arm A (control) is this class's default masked behaviour. Arm B
 * (treatment, real names) exists here but is gated behind
 * config('marathon.leaderboard_unmask_enabled'), which defaults OFF.
 *
 * H2051 — period tabs (Week/Month/All Time).
 */
class PranaLeaderboard
{
    public const PERIOD_WEEK = 'week';

    public const PERIOD_MONTH = 'month';

    public const PERIOD_ALL = 'all';

    /** @return list<string> */
    public static function periods(): array
    {
        return [self::PERIOD_WEEK, self::PERIOD_MONTH, self::PERIOD_ALL];
    }

    /**
     * Топ-$limit студентов. Если текущий пользователь не попал в топ, его строка
     * добавляется отдельно (с реальной позицией), чтобы он видел себя.
     *
     * @return Collection<int, array{position: int, display: string, lifetime: int, score: int, rank: string, is_me: bool, period: string}>
     */
    public static function rows(int $limit = 10, ?int $currentUserId = null, string $period = self::PERIOD_ALL): Collection
    {
        $period = self::normalizePeriod($period);

        if ($period === self::PERIOD_ALL) {
            return self::rowsAllTime($limit, $currentUserId);
        }

        return self::rowsForPeriod($period, $limit, $currentUserId);
    }

    /**
     * Все три периода одним вызовом (кабинет: вкладки Week / Month / All Time).
     *
     * @return array{week: Collection, month: Collection, all: Collection}
     */
    public static function rowsByPeriod(int $limit = 10, ?int $currentUserId = null): array
    {
        $out = [];
        foreach (self::periods() as $period) {
            $out[$period] = self::rows($limit, $currentUserId, $period);
        }

        return $out;
    }

    /** 1-based позиция студента в рейтинге за период. */
    public static function positionFor(int $userId, string $period = self::PERIOD_ALL): int
    {
        $period = self::normalizePeriod($period);
        $myScore = self::scoreForUser($userId, $period);

        if ($myScore <= 0) {
            return 0;
        }

        if ($period === self::PERIOD_ALL) {
            return self::baseUsersQuery()
                ->where('lifetime_prana', '>', $myScore)
                ->count() + 1;
        }

        $since = self::periodStart($period);

        $ahead = (int) PranaTransaction::query()
            ->select('user_id')
            ->selectRaw('SUM(amount) as score')
            ->where('amount', '>', 0)
            ->where('created_at', '>=', $since)
            ->whereHas('user', fn ($q) => $q->where('is_admin', false))
            ->groupBy('user_id')
            ->havingRaw('SUM(amount) > ?', [$myScore])
            ->get()
            ->count();

        return $ahead + 1;
    }

    public static function periodStart(string $period): CarbonInterface
    {
        $period = self::normalizePeriod($period);

        return match ($period) {
            self::PERIOD_WEEK => now()->startOfWeek(CarbonInterface::MONDAY),
            self::PERIOD_MONTH => now()->startOfMonth(),
            default => throw new \InvalidArgumentException("periodStart is not defined for '{$period}'"),
        };
    }

    public static function normalizePeriod(string $period): string
    {
        $period = strtolower(trim($period));

        return in_array($period, self::periods(), true) ? $period : self::PERIOD_ALL;
    }

    /**
     * @return Collection<int, array{position: int, display: string, lifetime: int, score: int, rank: string, is_me: bool, period: string}>
     */
    private static function rowsAllTime(int $limit, ?int $currentUserId): Collection
    {
        $top = self::baseUsersQuery()
            ->orderByDesc('lifetime_prana')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'lifetime_prana', 'lead_id']);

        $rows = $top->values()->map(
            fn (User $u, int $i) => self::row($u, $i + 1, (int) $u->lifetime_prana, $currentUserId, self::PERIOD_ALL)
        );

        if ($currentUserId !== null && ! $top->contains('id', $currentUserId)) {
            $me = User::find($currentUserId);
            if ($me && ! $me->is_admin && (int) ($me->lifetime_prana ?? 0) > 0) {
                $rows->push(self::row(
                    $me,
                    self::positionFor($currentUserId, self::PERIOD_ALL),
                    (int) $me->lifetime_prana,
                    $currentUserId,
                    self::PERIOD_ALL,
                ));
            }
        }

        return $rows;
    }

    /**
     * @return Collection<int, array{position: int, display: string, lifetime: int, score: int, rank: string, is_me: bool, period: string}>
     */
    private static function rowsForPeriod(string $period, int $limit, ?int $currentUserId): Collection
    {
        $since = self::periodStart($period);

        $aggregates = PranaTransaction::query()
            ->from('prana_transactions')
            ->join('users', 'users.id', '=', 'prana_transactions.user_id')
            ->where('users.is_admin', false)
            ->where('prana_transactions.amount', '>', 0)
            ->where('prana_transactions.created_at', '>=', $since)
            ->groupBy('prana_transactions.user_id')
            ->orderByDesc(DB::raw('SUM(prana_transactions.amount)'))
            ->orderBy('prana_transactions.user_id')
            ->limit($limit)
            ->get([
                'prana_transactions.user_id',
                DB::raw('SUM(prana_transactions.amount) as score'),
            ]);

        if ($aggregates->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $aggregates->pluck('user_id'))
            ->get(['id', 'name', 'lifetime_prana', 'lead_id'])
            ->keyBy('id');

        $rows = $aggregates->values()->map(function ($agg, int $i) use ($users, $currentUserId, $period) {
            $user = $users->get((int) $agg->user_id);
            if (! $user) {
                return null;
            }

            return self::row($user, $i + 1, (int) $agg->score, $currentUserId, $period);
        })->filter()->values();

        if ($currentUserId !== null && ! $aggregates->contains('user_id', $currentUserId)) {
            $myScore = self::scoreForUser($currentUserId, $period);
            $me = User::find($currentUserId);
            if ($me && ! $me->is_admin && $myScore > 0) {
                $rows->push(self::row(
                    $me,
                    self::positionFor($currentUserId, $period),
                    $myScore,
                    $currentUserId,
                    $period,
                ));
            }
        }

        return $rows;
    }

    private static function scoreForUser(int $userId, string $period): int
    {
        if ($period === self::PERIOD_ALL) {
            return (int) (User::query()->where('id', $userId)->value('lifetime_prana') ?? 0);
        }

        return (int) PranaTransaction::query()
            ->where('user_id', $userId)
            ->where('amount', '>', 0)
            ->where('created_at', '>=', self::periodStart($period))
            ->sum('amount');
    }

    private static function baseUsersQuery()
    {
        return User::query()
            ->where('is_admin', false)
            ->where('lifetime_prana', '>', 0);
    }

    /**
     * @return array{position: int, display: string, lifetime: int, score: int, rank: string, is_me: bool, period: string}
     */
    private static function row(User $u, int $position, int $score, ?int $currentUserId, string $period): array
    {
        return [
            'position' => $position,
            'display' => self::shouldUnmask($u) ? trim((string) $u->name) : self::maskName((string) $u->name),
            // `lifetime` kept for existing blade/tests; equals period score for week/month.
            'lifetime' => $score,
            'score' => $score,
            'rank' => PranaSettings::rankFor((int) ($u->lifetime_prana ?? 0))['name'],
            'is_me' => $currentUserId !== null && $u->id === $currentUserId,
            'period' => $period,
        ];
    }

    private static function shouldUnmask(User $u): bool
    {
        if (! (bool) config('marathon.leaderboard_unmask_enabled', false)) {
            return false;
        }

        return self::armFor($u) === MarathonEnrollment::ARM_B;
    }

    public static function armFor(User $u): ?string
    {
        if ($u->lead_id === null) {
            return null;
        }

        return MarathonEnrollment::where('lead_id', $u->lead_id)->value('ab_arm');
    }

    /** «Иванов Аруна, Москва» → «Иванов А.»; одно слово → как есть. */
    private static function maskName(string $name): string
    {
        $name = trim(explode(',', $name)[0]);
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) <= 1) {
            return $parts[0] ?? 'Студент';
        }

        return $parts[0].' '.mb_substr($parts[1], 0, 1).'.';
    }
}
