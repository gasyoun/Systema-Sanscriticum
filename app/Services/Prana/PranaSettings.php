<?php

declare(strict_types=1);

namespace App\Services\Prana;

use App\Models\MarketingSetting;

/**
 * Все знания про настройки праны в одном месте: читаем из MarketingSetting,
 * с фолбэком в config/prana.php. Кешируем строку настроек на время запроса —
 * чтобы не дёргать БД для каждого начисления и каждого рендера бейджа.
 *
 * Сбрасывать кеш при сохранении настроек админом не нужно: в админке после
 * сохранения форма перезагружается, а HTTP-запрос всегда поднимает свежий объект.
 */
final class PranaSettings
{
    private static ?MarketingSetting $cached = null;

    private static bool $cachedLoaded = false;

    public static function isActive(): bool
    {
        $row = self::row();
        if ($row === null) {
            return (bool) config('prana.enabled', true);
        }

        return (bool) $row->is_prana_active;
    }

    public static function rate(): int
    {
        $row = self::row();
        $value = $row?->prana_rate ?? (int) config('prana.rate', 10);

        return max(1, (int) $value);
    }

    /**
     * Доля цены в виде числа [0..1], которую можно покрыть праной.
     */
    public static function maxShare(): float
    {
        $row = self::row();
        if ($row !== null) {
            $percent = (int) $row->prana_max_share_percent;

            return max(0.0, min(1.0, $percent / 100));
        }

        return (float) config('prana.max_share_of_price', 0.30);
    }

    public static function reward(string $key): int
    {
        $row = self::row();
        $column = 'prana_reward_'.$key;

        if ($row !== null && isset($row->{$column})) {
            return (int) $row->{$column};
        }

        return (int) config('prana.rewards.'.$key, 0);
    }

    /**
     * @return array<string,int>
     */
    public static function allRewards(): array
    {
        $keys = ['lesson_complete', 'course_complete', 'open_lesson_view', 'daily_login', 'payment_success'];
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = self::reward($k);
        }

        return $out;
    }

    /**
     * Ранг по накопленной пране (lifetime). Возвращает текущий ранг и прогресс
     * к следующему (next=null, прогресс=100 для максимального).
     *
     * @return array{key: string, name: string, min: int, lifetime: int, next_name: ?string, next_min: ?int, progress: int}
     */
    public static function rankFor(int $lifetime): array
    {
        $ranks = config('prana.ranks', []);
        $lifetime = max(0, $lifetime);

        $current = $ranks[0] ?? ['key' => 'sishya', 'name' => 'Śiṣya', 'min' => 0];
        $next = null;

        foreach ($ranks as $i => $rank) {
            if ($lifetime >= (int) $rank['min']) {
                $current = $rank;
                $next = $ranks[$i + 1] ?? null;
            } else {
                break;
            }
        }

        if ($next === null) {
            $progress = 100;
        } else {
            $span = (int) $next['min'] - (int) $current['min'];
            $progress = $span > 0
                ? (int) round(($lifetime - (int) $current['min']) / $span * 100)
                : 0;
        }

        return [
            'key' => (string) $current['key'],
            'name' => (string) $current['name'],
            'min' => (int) $current['min'],
            'lifetime' => $lifetime,
            'next_name' => $next['name'] ?? null,
            'next_min' => isset($next['min']) ? (int) $next['min'] : null,
            'progress' => max(0, min(100, $progress)),
        ];
    }

    public static function flush(): void
    {
        self::$cached = null;
        self::$cachedLoaded = false;
    }

    private static function row(): ?MarketingSetting
    {
        if (! self::$cachedLoaded) {
            try {
                self::$cached = MarketingSetting::query()->first();
            } catch (\Throwable $e) {
                self::$cached = null;
            }
            self::$cachedLoaded = true;
        }

        return self::$cached;
    }
}
