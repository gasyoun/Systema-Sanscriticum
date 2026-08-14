<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;
use App\Models\StorefrontAnalyticsEvent;
use App\Services\Activity\StorefrontAnalytics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

/**
 * Isolated R12 / R15 tests on the Kochergina flagship (H2762).
 *
 * Flags default OFF. Window close restores the previous CTA and hides
 * the next-step strip — never ships a «winner».
 */
final class FlagshipExperiments
{
    public const FLAGSHIP_KEY = 'kochergina';

    public const VISITOR_COOKIE = 'shop_sf_vid';

    public const CTA_COOKIE = 'shop_cta_ab';

    public const TARGETS = ['buhler', 'texts', 'recitation'];

    private const COOKIE_MINUTES = 240 * 24 * 60;

    public static function flagshipKey(): string
    {
        $key = (string) config('flagship_experiments.flagship_key', self::FLAGSHIP_KEY);

        return $key !== '' ? $key : self::FLAGSHIP_KEY;
    }

    public static function isFlagship(Course $course): bool
    {
        return FlagshipLanding::keyFor($course) === self::flagshipKey();
    }

    public static function windowOpen(): bool
    {
        $started = config('flagship_experiments.started_at');
        if (! is_string($started) || $started === '') {
            return true;
        }

        $days = max(1, (int) config('flagship_experiments.window_days', 30));
        $start = Carbon::parse($started)->startOfDay();

        return now()->lt($start->copy()->addDays($days));
    }

    public static function nextStepEnabled(): bool
    {
        return (bool) config('features.catalog_next_step', false) && self::windowOpen();
    }

    public static function ctaAbEnabled(): bool
    {
        return (bool) config('features.flagship_cta_ab', false) && self::windowOpen();
    }

    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    public static function nextStepLinks(?Course $from = null): array
    {
        if (! self::nextStepEnabled()) {
            return [];
        }

        $items = [];
        foreach (config('flagship_experiments.next_step.targets', []) as $target) {
            $key = (string) ($target['key'] ?? '');
            if ($key === '' || ! in_array($key, self::TARGETS, true)) {
                continue;
            }

            $query = $from?->id ? ['from' => $from->id] : [];
            $items[] = [
                'key' => $key,
                'label' => (string) ($target['label'] ?? $key),
                'url' => route('shop.next-step', array_merge(['target' => $key], $query)),
            ];
        }

        return $items;
    }

    public static function nextStepEyebrow(): string
    {
        return (string) config('flagship_experiments.next_step.eyebrow', 'После этого →');
    }

    /**
     * @return array{variant: string, label: string}|null
     */
    public static function ctaFor(Course $course, Request $request): ?array
    {
        if (! self::ctaAbEnabled() || ! self::isFlagship($course) || ! $course->previewLesson) {
            return null;
        }

        $variant = self::ctaVariant($request);
        $label = $variant === 'b'
            ? (string) config('flagship_experiments.cta_ab.treatment', 'Записаться')
            : (string) config('flagship_experiments.cta_ab.control', 'Смотреть пробный урок');

        return [
            'variant' => $variant,
            'label' => $label,
        ];
    }

    public static function ctaVariant(Request $request): string
    {
        $cookieName = (string) config('flagship_experiments.cta_ab.cookie', self::CTA_COOKIE);
        $existing = strtolower((string) $request->cookie($cookieName));
        if (in_array($existing, ['a', 'b'], true)) {
            return $existing;
        }

        $assigned = random_int(0, 1) === 0 ? 'a' : 'b';
        Cookie::queue($cookieName, $assigned, self::COOKIE_MINUTES);

        return $assigned;
    }

    public static function ctaVariantFromRequest(Request $request): ?string
    {
        $cookieName = (string) config('flagship_experiments.cta_ab.cookie', self::CTA_COOKIE);
        $existing = strtolower((string) $request->cookie($cookieName));

        return in_array($existing, ['a', 'b'], true) ? $existing : null;
    }

    public static function visitorId(Request $request): string
    {
        $existing = (string) $request->cookie(self::VISITOR_COOKIE);
        $clean = preg_replace('/[^A-Fa-f0-9]/', '', $existing) ?? '';
        if (strlen($clean) === 32) {
            return $clean;
        }

        $fresh = bin2hex(random_bytes(16));
        Cookie::queue(self::VISITOR_COOKIE, $fresh, self::COOKIE_MINUTES);

        return $fresh;
    }

    public static function resolveTargetUrl(string $target): string
    {
        $catalog = route('shop.index');
        $spec = collect(config('flagship_experiments.next_step.targets', []))
            ->first(fn ($row) => ($row['key'] ?? '') === $target);

        $resolve = is_array($spec) ? (string) ($spec['resolve'] ?? '') : '';
        if (str_starts_with($resolve, 'flagship:')) {
            $key = substr($resolve, 9);
            $course = self::firstFlagshipCourse($key);

            return $course ? route('shop.course.show', $course->slug) : $catalog;
        }

        if (str_starts_with($resolve, 'pattern:')) {
            $pattern = substr($resolve, 8);
            $course = Course::query()
                ->where('is_visible', true)
                ->where('title', 'LIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], $pattern).'%')
                ->orderBy('id')
                ->first();

            return $course ? route('shop.course.show', $course->slug) : $catalog;
        }

        return $catalog;
    }

    public static function firstFlagshipCourse(string $key): ?Course
    {
        $match = config('flagship_landing.programs.'.$key.'.match', []);
        $needles = $match['slug_contains'] ?? [];
        if ($needles === []) {
            return null;
        }

        return Course::query()
            ->where('is_visible', true)
            ->where(function ($q) use ($needles) {
                foreach ($needles as $needle) {
                    $q->orWhere('slug', 'LIKE', '%'.str_replace(['%', '_'], ['\%', '\_'], (string) $needle).'%');
                }
            })
            ->orderBy('id')
            ->first();
    }

    public static function recordCardImpression(Course $course, Request $request): void
    {
        if (! self::nextStepEnabled() || ! self::isFlagship($course)) {
            return;
        }

        app(StorefrontAnalytics::class)->record(
            event: StorefrontAnalyticsEvent::CARD_IMPRESSION,
            experiment: StorefrontAnalyticsEvent::EXPERIMENT_NEXT_STEP,
            request: $request,
            course: $course,
            dedupeDay: true,
        );
    }
}
