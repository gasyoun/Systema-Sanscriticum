<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PranaTransaction;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Бейджи (достижения) студента — геймификация. Вычисляются из существующих
 * сигналов (пройденные уроки, завершённые курсы, рефералы, прана), без
 * отдельного хранилища: каталог в config('badges.catalog') = метрика + порог.
 */
class Badges
{
    /**
     * Бейджи пользователя: полученные сверху, далее по убыванию прогресса.
     *
     * @return Collection<int, array{key: string, name: string, icon: string, desc: string, earned: bool, value: int, threshold: int, progress: int}>
     */
    public static function for(User $user): Collection
    {
        $metrics = self::metricsFor($user);

        return collect(config('badges.catalog', []))
            ->map(function (array $b) use ($metrics): array {
                $value = (int) ($metrics[$b['metric']] ?? 0);
                $threshold = (int) $b['threshold'];

                return [
                    'key' => (string) $b['key'],
                    'name' => (string) $b['name'],
                    'icon' => (string) $b['icon'],
                    'desc' => (string) $b['desc'],
                    'earned' => $value >= $threshold,
                    'value' => $value,
                    'threshold' => $threshold,
                    'progress' => $threshold > 0 ? min(100, (int) round($value / $threshold * 100)) : 100,
                ];
            })
            ->sortByDesc(fn (array $b) => [$b['earned'] ? 1 : 0, $b['progress']])
            ->values();
    }

    /** Сколько бейджей получено. */
    public static function earnedCount(User $user): int
    {
        return self::for($user)->where('earned', true)->count();
    }

    /**
     * Метрики, по которым считаются бейджи.
     *
     * @return array<string, int>
     */
    public static function metricsFor(User $user): array
    {
        return [
            'lessons_completed' => $user->completedLessons()->count(),
            'courses_completed' => PranaTransaction::where('user_id', $user->id)
                ->where('reason', 'course_complete')->count(),
            'referrals' => ReferralReward::where('referrer_id', $user->id)->count(),
            'lifetime_prana' => (int) ($user->lifetime_prana ?? 0),
            'gifts_given' => PranaTransaction::where('user_id', $user->id)
                ->where('reason', 'p2p_sent')->count(),
        ];
    }
}
