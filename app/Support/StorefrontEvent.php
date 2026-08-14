<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LandingPage;
use Illuminate\Support\Carbon;

/**
 * Storefront display of dated promo CTAs (H2760).
 *
 * Past event_at → hide the date or relabel as a recording.
 * Never invents a calendar. Display-only: no grant or price change.
 */
class StorefrontEvent
{
    public const PAST_WEBINAR_LABEL = 'Запись вебинара';

    /** @var array<string, int> */
    private const MONTHS = [
        'января' => 1,
        'февраля' => 2,
        'марта' => 3,
        'апреля' => 4,
        'мая' => 5,
        'июня' => 6,
        'июля' => 7,
        'августа' => 8,
        'сентября' => 9,
        'октября' => 10,
        'ноября' => 11,
        'декабря' => 12,
    ];

    public static function monthPattern(): string
    {
        return implode('|', array_keys(self::MONTHS));
    }

    public static function containsCalendarDate(string $text): bool
    {
        return self::parseCalendarDate($text) !== null;
    }

    public static function parseCalendarDate(string $text, ?Carbon $now = null): ?Carbon
    {
        $now ??= now();
        $months = self::monthPattern();
        $pattern = '/(\d{1,2})(?:-го)?\s+('.$months.')(?:\s+(\d{4}))?(?:,?\s*(\d{1,2}):(\d{2}))?/iu';

        if (! preg_match($pattern, $text, $m)) {
            return null;
        }

        $monthKey = mb_strtolower($m[2]);
        $month = self::MONTHS[$monthKey] ?? null;
        if ($month === null) {
            return null;
        }

        $day = (int) $m[1];
        $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : (int) $now->year;
        $hour = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
        $minute = isset($m[5]) && $m[5] !== '' ? (int) $m[5] : 0;

        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return Carbon::create($year, $month, $day, $hour, $minute, 0, $now->timezoneName);
    }

    public static function eventAt(LandingPage $landing, ?Carbon $now = null): ?Carbon
    {
        if ($landing->webinar_date !== null) {
            return $landing->webinar_date;
        }

        $now ??= now();
        $fromTitle = self::parseCalendarDate((string) $landing->title, $now);
        if ($fromTitle !== null) {
            return $fromTitle;
        }

        return self::parseCalendarDate((string) ($landing->webinar_label ?? ''), $now);
    }

    public static function isPast(LandingPage $landing, ?Carbon $now = null): bool
    {
        $now ??= now();
        $eventAt = self::eventAt($landing, $now);

        return $eventAt !== null && $eventAt->lt($now);
    }

    public static function isUpcoming(LandingPage $landing, ?Carbon $now = null): bool
    {
        $now ??= now();
        $eventAt = self::eventAt($landing, $now);

        return $eventAt !== null && $eventAt->gte($now);
    }

    public static function storefrontTitle(LandingPage $landing, ?Carbon $now = null): string
    {
        $title = (string) $landing->title;
        if (self::isPast($landing, $now) && self::containsCalendarDate($title)) {
            return self::PAST_WEBINAR_LABEL;
        }

        return $title;
    }

    public static function storefrontBadge(LandingPage $landing, ?Carbon $now = null): ?string
    {
        $badge = $landing->webinar_label ?: null;
        if ($badge !== null && self::isPast($landing, $now) && self::containsCalendarDate($badge)) {
            return $landing->webinar_recording_url ? self::PAST_WEBINAR_LABEL : null;
        }

        return $badge;
    }

    public static function storefrontButtonText(LandingPage $landing, ?Carbon $now = null): string
    {
        $dated = (string) $landing->title.' '.($landing->webinar_label ?? '');
        if (self::isPast($landing, $now) && self::containsCalendarDate($dated)) {
            return $landing->webinar_recording_url ? 'Смотреть запись' : 'Подробнее';
        }

        return $landing->button_text ?: 'Записаться';
    }
}
