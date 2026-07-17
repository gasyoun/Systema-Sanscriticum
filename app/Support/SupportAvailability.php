<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * «Операторы онлайн?» — деловые часы куратора для веб-чата (H1199, Jivo-паритет
 * S4/S5: оффлайн-форма «напишем на почту» вместо иллюзии живого ответа). Никакого
 * реального presence-трекинга кураторов (это НЕ дублирует S2/H1197 — тот слой про
 * посетителя, не про оператора): простое расписание config/support_hours.php,
 * человек его настраивает. Когда флаг выключен — считаем «всегда онлайн» (текущее
 * поведение виджета не меняется).
 */
class SupportAvailability
{
    public static function isOnline(?Carbon $at = null): bool
    {
        if (! config('support_hours.enabled')) {
            return true;
        }

        $tz = config('support_hours.timezone', 'Europe/Moscow');
        $now = ($at ?? now())->copy()->setTimezone($tz);

        $day = strtolower($now->format('D'));
        $window = config("support_hours.schedule.{$day}");

        if (! is_array($window) || count($window) !== 2) {
            return false;
        }

        [$start, $end] = $window;
        $time = $now->format('H:i');

        return $time >= $start && $time < $end;
    }
}
