<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Services\Prana\PranaService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Серия активных дней подряд (streak) — геймификация. Вызывается из
 * TrackUserActivity раз в день (за счёт guard по streak_last_day): +1 за новый
 * день, сброс при пропуске; на вехах (config('prana.streak_bonuses')) — бонус праны.
 */
class StreakService
{
    public function __construct(private PranaService $prana) {}

    /**
     * Отметить активность пользователя сегодня. Идемпотентно в пределах дня.
     * Возвращает текущую длину серии.
     */
    public function touch(User $user): int
    {
        $today = Carbon::today();
        $last = $user->streak_last_day ? Carbon::parse($user->streak_last_day)->startOfDay() : null;

        // Уже засчитали сегодня — ничего не делаем.
        if ($last && $last->isSameDay($today)) {
            return (int) $user->streak_days;
        }

        // Вчера была активность → серия продолжается; иначе (пропуск/первый раз) — сброс на 1.
        $streak = ($last && $last->copy()->addDay()->isSameDay($today))
            ? (int) $user->streak_days + 1
            : 1;

        DB::table('users')->where('id', $user->id)->update([
            'streak_days' => $streak,
            'streak_last_day' => $today->toDateString(),
        ]);
        // Держим модель в актуальном состоянии (на случай дальнейшего чтения в запросе).
        $user->forceFill(['streak_days' => $streak, 'streak_last_day' => $today->toDateString()])->syncOriginal();

        // Бонус за веху (source=null: idempotency обеспечивает дневной guard выше).
        $bonus = (int) (config('prana.streak_bonuses')[$streak] ?? 0);
        if ($bonus > 0) {
            $this->prana->award($user, 'streak', source: null, amount: $bonus, meta: ['streak_days' => $streak]);
        }

        return $streak;
    }

    /**
     * Следующая веха и сколько дней до неё для текущей серии (или null, если выше всех вех).
     *
     * @return array{next: int, remaining: int}|null
     */
    public static function nextMilestone(int $streakDays): ?array
    {
        $milestones = array_keys(config('prana.streak_bonuses', []));
        sort($milestones);

        foreach ($milestones as $m) {
            if ($streakDays < $m) {
                return ['next' => (int) $m, 'remaining' => (int) $m - $streakDays];
            }
        }

        return null;
    }
}
