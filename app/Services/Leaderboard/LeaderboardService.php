<?php

declare(strict_types=1);

namespace App\Services\Leaderboard;

use App\Models\MarathonEnrollment;
use App\Models\SrsDeck;
use App\Models\User;
use App\Services\Prana\PranaSettings;
use App\Support\PranaLeaderboard;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-board gamification rankings (H2052). Config-driven; disable boards in
 * config/leaderboards.php without code changes.
 */
class LeaderboardService
{
    /**
     * @return list<array{key: string, label: string, hint: ?string, periods: array<string, Collection>}>
     */
    public function allEnabledBoards(int $limit = 10, ?int $currentUserId = null): array
    {
        $out = [];
        foreach (config('leaderboards.boards', []) as $key => $meta) {
            if (! ($meta['enabled'] ?? false)) {
                continue;
            }
            $periods = [];
            foreach (PranaLeaderboard::periods() as $period) {
                $periods[$period] = $this->rows($key, $period, $limit, $currentUserId);
            }
            $out[] = [
                'key' => $key,
                'label' => (string) ($meta['label'] ?? $key),
                'hint' => $meta['hint'] ?? null,
                'periods' => $periods,
            ];
        }

        return $out;
    }

    /**
     * @return Collection<int, array{position: int, display: string, score: int, lifetime: int, rank: string, is_me: bool, period: string, board: string}>
     */
    public function rows(string $boardKey, string $period = 'all', int $limit = 10, ?int $currentUserId = null): Collection
    {
        $period = PranaLeaderboard::normalizePeriod($period);
        $meta = config("leaderboards.boards.{$boardKey}");
        if (! is_array($meta) || ! ($meta['enabled'] ?? false)) {
            return collect();
        }

        $source = (string) ($meta['source'] ?? '');
        $filter = $meta['filter'] ?? null;

        // Pre-migrate safety: never blow up the cabinet if tables are absent.
        if ($source === 'srs' && ! Schema::hasTable('srs_review_logs')) {
            return collect();
        }
        if ($source === 'lila' && ! Schema::hasTable('lila_score_events')) {
            return collect();
        }
        if ($source === 'memrise_import' && ! Schema::hasTable('memrise_leaderboard_imports')) {
            return collect();
        }
        if ($source === 'combined' && ! Schema::hasTable('prana_transactions')) {
            return collect();
        }

        return match ($source) {
            'prana' => $this->adaptPrana($period, $limit, $currentUserId, $boardKey),
            'srs' => $this->fromAggregates(
                $this->srsScores($period, is_string($filter) ? $filter : null),
                $limit,
                $currentUserId,
                $period,
                $boardKey,
            ),
            'lila' => $this->fromAggregates(
                $this->lilaScores($period, is_string($filter) ? $filter : null),
                $limit,
                $currentUserId,
                $period,
                $boardKey,
            ),
            'memrise_import' => $this->memriseImportRows(
                is_string($filter) ? $filter : null,
                $limit,
                $currentUserId,
                $period,
                $boardKey,
            ),
            'combined' => $this->fromAggregates(
                $this->combinedScores($period),
                $limit,
                $currentUserId,
                $period,
                $boardKey,
            ),
            default => collect(),
        };
    }

    /**
     * @return Collection<int, array{position: int, display: string, score: int, lifetime: int, rank: string, is_me: bool, period: string, board: string}>
     */
    private function adaptPrana(string $period, int $limit, ?int $currentUserId, string $boardKey): Collection
    {
        return PranaLeaderboard::rows($limit, $currentUserId, $period)->map(function (array $row) use ($boardKey) {
            $row['board'] = $boardKey;
            $row['score'] = (int) ($row['score'] ?? $row['lifetime'] ?? 0);
            $row['lifetime'] = $row['score'];

            return $row;
        });
    }

    /**
     * @param  Collection<int, object{user_id: int, score: int|float|string}>  $aggregates
     * @return Collection<int, array{position: int, display: string, score: int, lifetime: int, rank: string, is_me: bool, period: string, board: string}>
     */
    private function fromAggregates(
        Collection $aggregates,
        int $limit,
        ?int $currentUserId,
        string $period,
        string $boardKey,
    ): Collection {
        $aggregates = $aggregates
            ->filter(fn ($r) => (int) $r->score > 0)
            ->sortByDesc(fn ($r) => (int) $r->score)
            ->values();

        $top = $aggregates->take($limit);
        if ($top->isEmpty()) {
            return collect();
        }

        $users = User::query()
            ->whereIn('id', $top->pluck('user_id'))
            ->where('is_admin', false)
            ->get(['id', 'name', 'lifetime_prana', 'lead_id'])
            ->keyBy('id');

        $rows = $top->values()->map(function ($agg, int $i) use ($users, $currentUserId, $period, $boardKey) {
            $user = $users->get((int) $agg->user_id);
            if (! $user) {
                return null;
            }

            return $this->row($user, $i + 1, (int) $agg->score, $currentUserId, $period, $boardKey);
        })->filter()->values();

        if ($currentUserId !== null && ! $top->contains(fn ($a) => (int) $a->user_id === $currentUserId)) {
            $mine = $aggregates->first(fn ($a) => (int) $a->user_id === $currentUserId);
            $me = User::find($currentUserId);
            if ($mine && $me && ! $me->is_admin && (int) $mine->score > 0) {
                $ahead = $aggregates->filter(fn ($a) => (int) $a->score > (int) $mine->score)->count();
                $rows->push($this->row($me, $ahead + 1, (int) $mine->score, $currentUserId, $period, $boardKey));
            }
        }

        return $rows;
    }

    /**
     * @return Collection<int, object{user_id: int, score: int|float|string}>
     */
    private function srsScores(string $period, ?string $slugPrefix): Collection
    {
        // FSRS ratings 1..4 → points (Again..Easy). Literal CASE for MySQL/SQLite.
        $scoreExpr = 'SUM(CASE srs_review_logs.rating '
            .'WHEN 1 THEN 1 WHEN 2 THEN 2 WHEN 3 THEN 3 WHEN 4 THEN 4 ELSE 1 END)';

        $q = DB::table('srs_review_logs')
            ->join('users', 'users.id', '=', 'srs_review_logs.user_id')
            ->where('users.is_admin', false);

        if ($period !== PranaLeaderboard::PERIOD_ALL) {
            $q->where('srs_review_logs.reviewed_at', '>=', $this->periodStart($period));
        }

        if ($slugPrefix !== null && $slugPrefix !== '') {
            $deckIds = SrsDeck::query()
                ->where('slug', 'like', $slugPrefix.'%')
                ->pluck('id');
            if ($deckIds->isEmpty()) {
                return collect();
            }
            $q->whereIn('srs_review_logs.deck_id', $deckIds);
        }

        return $q
            ->groupBy('srs_review_logs.user_id')
            ->orderByDesc(DB::raw($scoreExpr))
            ->get([
                'srs_review_logs.user_id',
                DB::raw("{$scoreExpr} as score"),
            ]);
    }

    /**
     * @return Collection<int, object{user_id: int, score: int|float|string}>
     */
    private function lilaScores(string $period, ?string $drill): Collection
    {
        $q = DB::table('lila_score_events')
            ->join('users', 'users.id', '=', 'lila_score_events.user_id')
            ->where('users.is_admin', false);

        if ($period !== PranaLeaderboard::PERIOD_ALL) {
            $q->where('lila_score_events.created_at', '>=', $this->periodStart($period));
        }
        if ($drill !== null && $drill !== '') {
            $q->where('lila_score_events.drill', $drill);
        }

        return $q
            ->groupBy('lila_score_events.user_id')
            ->orderByDesc(DB::raw('SUM(lila_score_events.points)'))
            ->get([
                'lila_score_events.user_id',
                DB::raw('SUM(lila_score_events.points) as score'),
            ]);
    }

    /**
     * Memrise import is a static all-time snapshot; week/month show the same
     * ranking until a fresh CSV is imported. Shows unlinked Memrise usernames
     * (so the transfer board is visible before claim) and linked Systema users.
     *
     * @return Collection<int, array{position: int, display: string, score: int, lifetime: int, rank: string, is_me: bool, period: string, board: string}>
     */
    private function memriseImportRows(
        ?string $courseId,
        int $limit,
        ?int $currentUserId,
        string $period,
        string $boardKey,
    ): Collection {
        $q = DB::table('memrise_leaderboard_imports');
        if ($courseId !== null && $courseId !== '') {
            $q->where('course_id', $courseId);
        }

        // One row per (course, username, period) already unique; sum points across courses if filter null.
        $rows = $q
            ->orderByDesc('points')
            ->get(['id', 'course_id', 'username', 'points', 'user_id', 'rank']);

        // Collapse by linked user_id when set, else by username (case-insensitive).
        $agg = [];
        foreach ($rows as $r) {
            $key = $r->user_id !== null
                ? 'u:'.(int) $r->user_id
                : 'm:'.mb_strtolower(trim((string) $r->username));
            if (! isset($agg[$key])) {
                $agg[$key] = (object) [
                    'user_id' => $r->user_id !== null ? (int) $r->user_id : null,
                    'username' => (string) $r->username,
                    'score' => 0,
                ];
            }
            $agg[$key]->score += (int) $r->points;
        }

        $aggregates = collect($agg)
            ->filter(fn ($r) => (int) $r->score > 0)
            ->sortByDesc(fn ($r) => (int) $r->score)
            ->values();

        if ($aggregates->isEmpty()) {
            return collect();
        }

        $userIds = $aggregates->pluck('user_id')->filter()->unique()->values();
        $users = $userIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $userIds)
                ->where('is_admin', false)
                ->get(['id', 'name', 'lifetime_prana', 'lead_id'])
                ->keyBy('id');

        $top = $aggregates->take($limit);
        $out = $top->values()->map(function ($agg, int $i) use ($users, $currentUserId, $period, $boardKey) {
            if ($agg->user_id !== null) {
                $user = $users->get((int) $agg->user_id);
                if (! $user) {
                    // Admin or deleted — fall back to Memrise username.
                    return [
                        'position' => $i + 1,
                        'display' => (string) $agg->username,
                        'score' => (int) $agg->score,
                        'lifetime' => (int) $agg->score,
                        'rank' => 'Memrise',
                        'is_me' => false,
                        'period' => $period,
                        'board' => $boardKey,
                    ];
                }

                return $this->row($user, $i + 1, (int) $agg->score, $currentUserId, $period, $boardKey);
            }

            return [
                'position' => $i + 1,
                'display' => (string) $agg->username,
                'score' => (int) $agg->score,
                'lifetime' => (int) $agg->score,
                'rank' => 'Memrise (не привязан)',
                'is_me' => false,
                'period' => $period,
                'board' => $boardKey,
            ];
        })->values();

        if ($currentUserId !== null && ! $out->contains(fn ($r) => $r['is_me'])) {
            $mine = $aggregates->first(fn ($a) => $a->user_id !== null && (int) $a->user_id === $currentUserId);
            $me = User::find($currentUserId);
            if ($mine && $me && ! $me->is_admin && (int) $mine->score > 0) {
                $ahead = $aggregates->filter(fn ($a) => (int) $a->score > (int) $mine->score)->count();
                $out->push($this->row($me, $ahead + 1, (int) $mine->score, $currentUserId, $period, $boardKey));
            }
        }

        return $out;
    }

    /**
     * Linked-user totals only (for combined board).
     *
     * @return Collection<int, object{user_id: int, score: int|float|string}>
     */
    private function memriseImportScores(?string $courseId): Collection
    {
        $q = DB::table('memrise_leaderboard_imports')
            ->whereNotNull('user_id')
            ->join('users', 'users.id', '=', 'memrise_leaderboard_imports.user_id')
            ->where('users.is_admin', false);

        if ($courseId !== null && $courseId !== '') {
            $q->where('memrise_leaderboard_imports.course_id', $courseId);
        }

        return $q
            ->groupBy('memrise_leaderboard_imports.user_id')
            ->orderByDesc(DB::raw('SUM(memrise_leaderboard_imports.points)'))
            ->get([
                'memrise_leaderboard_imports.user_id',
                DB::raw('SUM(memrise_leaderboard_imports.points) as score'),
            ]);
    }

    /**
     * @return Collection<int, object{user_id: int, score: int|float|string}>
     */
    private function combinedScores(string $period): Collection
    {
        $map = [];

        $add = function (Collection $rows) use (&$map): void {
            foreach ($rows as $r) {
                $uid = (int) $r->user_id;
                $map[$uid] = ($map[$uid] ?? 0) + (int) $r->score;
            }
        };

        if ($period === PranaLeaderboard::PERIOD_ALL) {
            User::query()
                ->where('is_admin', false)
                ->where('lifetime_prana', '>', 0)
                ->get(['id', 'lifetime_prana'])
                ->each(function (User $u) use (&$map) {
                    $map[$u->id] = ($map[$u->id] ?? 0) + (int) $u->lifetime_prana;
                });
        } else {
            $since = $this->periodStart($period);
            $add(DB::table('prana_transactions')
                ->join('users', 'users.id', '=', 'prana_transactions.user_id')
                ->where('users.is_admin', false)
                ->where('prana_transactions.amount', '>', 0)
                ->where('prana_transactions.created_at', '>=', $since)
                ->groupBy('prana_transactions.user_id')
                ->get([
                    'prana_transactions.user_id',
                    DB::raw('SUM(prana_transactions.amount) as score'),
                ]));
        }

        if (Schema::hasTable('srs_review_logs')) {
            $add($this->srsScores($period, null));
        }
        if (Schema::hasTable('lila_score_events')) {
            $add($this->lilaScores($period, null));
        }
        if ($period === PranaLeaderboard::PERIOD_ALL && Schema::hasTable('memrise_leaderboard_imports')) {
            $add($this->memriseImportScores(null));
        }

        return collect($map)->map(fn (int $score, int $userId) => (object) [
            'user_id' => $userId,
            'score' => $score,
        ])->values();
    }

    private function periodStart(string $period): CarbonInterface
    {
        return PranaLeaderboard::periodStart($period);
    }

    /**
     * @return array{position: int, display: string, score: int, lifetime: int, rank: string, is_me: bool, period: string, board: string}
     */
    private function row(User $u, int $position, int $score, ?int $currentUserId, string $period, string $boardKey): array
    {
        // Reuse masking via a public-ish path: call PranaLeaderboard through reflection-free adapter
        $display = $this->maskName((string) $u->name);
        if (PranaLeaderboard::armFor($u) === MarathonEnrollment::ARM_B
            && (bool) config('marathon.leaderboard_unmask_enabled', false)) {
            $display = trim((string) $u->name);
        }

        return [
            'position' => $position,
            'display' => $display,
            'score' => $score,
            'lifetime' => $score,
            'rank' => PranaSettings::rankFor((int) ($u->lifetime_prana ?? 0))['name'],
            'is_me' => $currentUserId !== null && $u->id === $currentUserId,
            'period' => $period,
            'board' => $boardKey,
        ];
    }

    private function maskName(string $name): string
    {
        $name = trim(explode(',', $name)[0]);
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) <= 1) {
            return $parts[0] ?? 'Студент';
        }

        return $parts[0].' '.mb_substr($parts[1], 0, 1).'.';
    }
}
