<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarathonEnrollment;
use App\Models\User;
use App\Services\Prana\PranaSettings;
use Illuminate\Support\Collection;

/**
 * Таблица лидеров по накопленной пране (lifetime_prana) — геймификация поверх
 * рангов. Имена маскируются (имя + инициал), чтобы не светить ФИО/город целиком.
 * Считаются только студенты (не админы) с lifetime > 0.
 *
 * H447 §5 — leaderboard A/B (Uprava/docs/SARASWATI_TRAINERS_2026.md §5).
 * Arm A (control) is this class's default masked behaviour. Arm B
 * (treatment, real names) exists here but is gated behind
 * config('marathon.leaderboard_unmask_enabled'), which defaults OFF — arm B
 * un-masks student identities to peers and needs an explicit enrolment-
 * consent decision before it can ship. See {@see self::shouldUnmask()}.
 */
class PranaLeaderboard
{
    /**
     * Топ-$limit студентов. Если текущий пользователь не попал в топ, его строка
     * добавляется отдельно (с реальной позицией), чтобы он видел себя.
     *
     * @return Collection<int, array{position: int, display: string, lifetime: int, rank: string, is_me: bool}>
     */
    public static function rows(int $limit = 10, ?int $currentUserId = null): Collection
    {
        $top = self::baseQuery()
            ->orderByDesc('lifetime_prana')
            ->orderBy('id') // стабильный порядок при равенстве
            ->limit($limit)
            // lead_id обязателен в select — им shouldUnmask()/armFor() находят
            // marathon_enrollments.ab_arm (H447 §5); без него столбец не
            // подгружается и любой студент читается как «без арма».
            ->get(['id', 'name', 'lifetime_prana', 'lead_id']);

        $rows = $top->values()->map(fn (User $u, int $i) => self::row($u, $i + 1, $currentUserId));

        // Текущий пользователь вне топа — добавим его строку с реальной позицией.
        if ($currentUserId !== null && ! $top->contains('id', $currentUserId)) {
            $me = User::find($currentUserId);
            if ($me && ! $me->is_admin && (int) ($me->lifetime_prana ?? 0) > 0) {
                $rows->push(self::row($me, self::positionFor($currentUserId), $currentUserId));
            }
        }

        return $rows;
    }

    /** 1-based позиция студента в общем рейтинге по lifetime_prana. */
    public static function positionFor(int $userId): int
    {
        $me = User::find($userId);
        $myLifetime = (int) ($me?->lifetime_prana ?? 0);

        return self::baseQuery()
            ->where('lifetime_prana', '>', $myLifetime)
            ->count() + 1;
    }

    private static function baseQuery()
    {
        return User::query()
            ->where('is_admin', false)
            ->where('lifetime_prana', '>', 0);
    }

    /**
     * @return array{position: int, display: string, lifetime: int, rank: string, is_me: bool}
     */
    private static function row(User $u, int $position, ?int $currentUserId): array
    {
        return [
            'position' => $position,
            'display' => self::shouldUnmask($u) ? trim((string) $u->name) : self::maskName((string) $u->name),
            'lifetime' => (int) $u->lifetime_prana,
            'rank' => PranaSettings::rankFor((int) $u->lifetime_prana)['name'],
            'is_me' => $currentUserId !== null && $u->id === $currentUserId,
        ];
    }

    /**
     * True only when the global un-mask gate is on AND this user's marathon
     * arm is B. The gate defaults OFF (config/marathon.php) — see the class
     * docblock. Arm lookup goes through User::lead_id → MarathonEnrollment,
     * since arm is assigned at the (pre-account) Lead stage — see
     * MarathonEnrollment::computeArm().
     */
    private static function shouldUnmask(User $u): bool
    {
        if (! (bool) config('marathon.leaderboard_unmask_enabled', false)) {
            return false;
        }

        return self::armFor($u) === MarathonEnrollment::ARM_B;
    }

    /** The student's marathon A/B arm, or null if they have no (linked) marathon enrolment. */
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
        $name = trim(explode(',', $name)[0]); // отрезаем город
        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) <= 1) {
            return $parts[0] ?? 'Студент';
        }

        return $parts[0].' '.mb_substr($parts[1], 0, 1).'.';
    }
}
