<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for the shop free-intro CTA next date (H2365).
 *
 * Never invents a calendar and never hard-codes a promotional date.
 *
 * Resolution order (first hit wins):
 * 1. Earliest future Schedule referenced by a visible Course.trial_schedule_id
 *    — the designated intro/trial session already used by trial purchase.
 * 2. Else earliest future webinar_date on an active LandingPage
 *    — free webinar landings (lead form).
 *
 * Empty source → null; UI omits the date line (H2760). Never invent a date.
 * {@see FALLBACK_LABEL} is kept for programmatic callers only — shop Blade must not render it.
 */
class NextIntroSession
{
    public const FALLBACK_LABEL = 'дата уточняется';

    public const CACHE_KEY = 'shop_next_intro_session_v1';

    public const CACHE_TTL_SECONDS = 120;

    /**
     * @return array{
     *     source: 'trial_schedule'|'landing_webinar',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }|null
     */
    public static function resolve(?Carbon $now = null, bool $useCache = true): ?array
    {
        $now ??= now();

        if (! $useCache || app()->environment('testing')) {
            return self::resolveFresh($now);
        }

        /** @var array|null $cached */
        $cached = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function () use ($now) {
            return self::serializeForCache(self::resolveFresh($now));
        });

        return self::hydrateFromCache($cached);
    }

    /**
     * Honest date string for templates: real local date, or {@see FALLBACK_LABEL}.
     */
    public static function dateLabel(?Carbon $now = null, bool $useCache = true): string
    {
        $hit = self::resolve($now, $useCache);

        return $hit['date_label'] ?? self::FALLBACK_LABEL;
    }

    /**
     * Per-course intro/trial session when the course has a future trial_schedule.
     *
     * @return array{
     *     source: 'trial_schedule',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }|null
     */
    public static function forCourse(Course $course, ?Carbon $now = null): ?array
    {
        $now ??= now();
        $course->loadMissing('trialSchedule');
        $schedule = $course->trialSchedule;

        if (! $schedule?->start || $schedule->start->lt($now)) {
            return null;
        }

        return self::fromTrialSchedule($course, $schedule);
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{
     *     source: 'trial_schedule'|'landing_webinar',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }|null
     */
    private static function resolveFresh(Carbon $now): ?array
    {
        $trial = self::earliestTrialSchedule($now);
        if ($trial !== null) {
            return $trial;
        }

        return self::earliestLandingWebinar($now);
    }

    /**
     * @return array{
     *     source: 'trial_schedule',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }|null
     */
    private static function earliestTrialSchedule(Carbon $now): ?array
    {
        $course = Course::query()
            ->where('is_visible', true)
            ->whereNotNull('trial_schedule_id')
            ->whereHas('trialSchedule', function ($q) use ($now) {
                $q->where('start', '>=', $now);
            })
            ->with(['trialSchedule' => function ($q) {
                $q->orderBy('start');
            }])
            ->get()
            ->filter(fn (Course $c) => $c->trialSchedule?->start !== null)
            ->sortBy(fn (Course $c) => $c->trialSchedule->start->timestamp)
            ->first();

        if ($course === null || $course->trialSchedule === null) {
            return null;
        }

        return self::fromTrialSchedule($course, $course->trialSchedule);
    }

    /**
     * @return array{
     *     source: 'landing_webinar',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }|null
     */
    private static function earliestLandingWebinar(Carbon $now): ?array
    {
        $landing = LandingPage::query()
            ->where('is_active', true)
            ->whereNotNull('webinar_date')
            ->where('webinar_date', '>=', $now)
            ->orderBy('webinar_date')
            ->first();

        if ($landing === null || $landing->webinar_date === null) {
            return null;
        }

        $startsAt = $landing->webinar_date;
        $label = $landing->webinar_label ?: 'Бесплатное вводное занятие';

        return [
            'source' => 'landing_webinar',
            'starts_at' => $startsAt,
            'date_label' => self::formatDateLabel($startsAt),
            'cta_url' => route('promo.show', $landing->slug),
            'cta_label' => $label,
            'is_free' => true,
            'course_title' => $landing->title,
            'course_slug' => null,
            'schedule_id' => null,
            'landing_slug' => $landing->slug,
        ];
    }

    /**
     * @return array{
     *     source: 'trial_schedule',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }
     */
    private static function fromTrialSchedule(Course $course, Schedule $schedule): array
    {
        $startsAt = $schedule->start;
        $price = (float) ($course->trial_price ?? 0);
        $isFree = $price <= 0.0;

        return [
            'source' => 'trial_schedule',
            'starts_at' => $startsAt,
            'date_label' => self::formatDateLabel($startsAt),
            'cta_url' => route('shop.course.show', $course->slug),
            'cta_label' => $isFree
                ? 'Бесплатное вводное занятие'
                : 'Пробное занятие',
            'is_free' => $isFree,
            'course_title' => $course->title,
            'course_slug' => $course->slug,
            'schedule_id' => $schedule->id,
            'landing_slug' => null,
        ];
    }

    /**
     * Shop banner date. PHP `translatedFormat` tokens, not ICU: `MMMM` is four
     * copies of `M` and printed `сентябрясентябрясентября` (H3651 / Systema #2176).
     */
    public static function formatDateLabel(Carbon $startsAt): string
    {
        return $startsAt
            ->timezone(config('app.timezone'))
            ->locale('ru')
            ->translatedFormat('d F Y, H:i');
    }

    /**
     * @param  array{
     *     source: 'trial_schedule'|'landing_webinar',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }|null  $hit
     * @return array<string, mixed>|null
     */
    private static function serializeForCache(?array $hit): ?array
    {
        if ($hit === null) {
            return null;
        }

        return [
            'source' => $hit['source'],
            'starts_at' => $hit['starts_at']->toIso8601String(),
            'date_label' => $hit['date_label'],
            'cta_url' => $hit['cta_url'],
            'cta_label' => $hit['cta_label'],
            'is_free' => $hit['is_free'],
            'course_title' => $hit['course_title'],
            'course_slug' => $hit['course_slug'],
            'schedule_id' => $hit['schedule_id'],
            'landing_slug' => $hit['landing_slug'],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $cached
     * @return array{
     *     source: 'trial_schedule'|'landing_webinar',
     *     starts_at: Carbon,
     *     date_label: string,
     *     cta_url: string,
     *     cta_label: string,
     *     is_free: bool,
     *     course_title: ?string,
     *     course_slug: ?string,
     *     schedule_id: ?int,
     *     landing_slug: ?string,
     * }|null
     */
    private static function hydrateFromCache(?array $cached): ?array
    {
        if ($cached === null) {
            return null;
        }

        return [
            'source' => $cached['source'],
            'starts_at' => Carbon::parse($cached['starts_at']),
            'date_label' => $cached['date_label'],
            'cta_url' => $cached['cta_url'],
            'cta_label' => $cached['cta_label'],
            'is_free' => (bool) $cached['is_free'],
            'course_title' => $cached['course_title'] ?? null,
            'course_slug' => $cached['course_slug'] ?? null,
            'schedule_id' => $cached['schedule_id'] ?? null,
            'landing_slug' => $cached['landing_slug'] ?? null,
        ];
    }
}
