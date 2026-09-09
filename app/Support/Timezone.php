<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * H4434 — timezone display helper (MG 09-09-2026).
 *
 * Один источник правды для рендера времени ученику:
 *  - MSK-резиденты видят как раньше (голое московское время);
 *  - нон-МСК получают dual-display «11:00 МСК · 16:00 ваше» (MG ruling).
 *
 * Хранение: приложение живёт в Europe/Moscow (config/app.php), schedules.start —
 * наивное московское wall-time. Конверсия — только при рендере.
 */
class Timezone
{
    public const APP_TZ = 'Europe/Moscow';

    /**
     * Отформатировать момент для ученика.
     *
     * @param  Carbon|null  $at  момент в app TZ (наивное московское время)
     * @param  string|null  $userTz  эффективная IANA-зона ученика (null = МСК)
     * @param  string  $format  формат локальной части (дата форматируется отдельно в Blade при нужде)
     * @return string «11:00» | «11:00 МСК · 16:00 ваше»
     */
    public static function render(?Carbon $at, ?string $userTz, string $format = 'H:i'): string
    {
        if ($at === null) {
            return '';
        }

        $msk = $at->timezone(self::APP_TZ)->format($format);

        if ($userTz === null || $userTz === '' || $userTz === self::APP_TZ) {
            return $msk;
        }

        $local = $at->timezone($userTz)->format($format);

        if ($local === $msk) {
            return $msk;
        }

        return $msk.' МСК · '.$local.' ваше';
    }

    /**
     * День недели + дата в зоне ученика (заголовки «Сегодня/суббота» в кабинете
     * должны считаться в TZ юзера — иначе «суббота» у калифорнийца = «пятница»).
     *
     * @param  Carbon  $at  момент в app TZ
     * @param  string|null  $userTz  эффективная зона ученика
     * @param  string  $format  формат даты
     */
    public static function dayLabel(Carbon $at, ?string $userTz, string $format = 'd.m.Y'): string
    {
        $tz = ($userTz !== null && $userTz !== '') ? $userTz : self::APP_TZ;

        return $at->timezone($tz)->format($format);
    }

    /**
     * Валидна ли IANA-строка (защита селектора и JS-детекта от мусора).
     */
    public static function isValid(?string $tz): bool
    {
        if ($tz === null || $tz === '' || strlen($tz) > 64) {
            return false;
        }

        try {
            new \DateTimeZone($tz);
        } catch (\Exception) {
            return false;
        }

        return true;
    }
}
