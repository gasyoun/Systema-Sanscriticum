<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use App\Services\Prana\PranaSettings;
use Illuminate\Support\Collection;

/**
 * Таблица лидеров по накопленной пране (lifetime_prana) — геймификация поверх
 * рангов. Имена маскируются (имя + инициал), чтобы не светить ФИО/город целиком.
 * Считаются только студенты (не админы) с lifetime > 0.
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
            ->get(['id', 'name', 'lifetime_prana']);

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
            'display' => self::maskName((string) $u->name),
            'lifetime' => (int) $u->lifetime_prana,
            'rank' => PranaSettings::rankFor((int) $u->lifetime_prana)['name'],
            'is_me' => $currentUserId !== null && $u->id === $currentUserId,
        ];
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
