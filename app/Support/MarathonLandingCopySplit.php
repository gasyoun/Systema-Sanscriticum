<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\MarathonLandingCopyVariantEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

/**
 * H2010 — real per-visitor 50/50 split for MarathonLandingCopy's copy
 * variant. MG ruling 31-07-2026 ("not human decides -- do A/B testing until
 * 1 November, numbers will decide after") overrides the earlier sequential
 * A-then-B-via-env window in MarathonLandingCopy's own docblock. Independent
 * of MarathonVisual's skin axis -- do not conflate.
 *
 * Sticky per visitor via a long-lived cookie, assigned once at first
 * landing hit. New random assignments stop at
 * config('marathon_landing_copy.ab_test_until'); on/after that date every
 * visitor gets MarathonLandingCopy::variantKey() (the human-set
 * post-experiment default) and no impression is recorded, so nobody is
 * fabricated a "test" variant they were never actually shown.
 */
final class MarathonLandingCopySplit
{
    public const COOKIE_NAME = 'mkt_copy_variant';

    // 240 days -- comfortably outlives the 2026-11-01 cutoff from any visit
    // date, so a visitor keeps their arm for the whole live test window.
    private const COOKIE_MINUTES = 240 * 24 * 60;

    public function __construct(
        public readonly string $variantKey,
        public readonly bool $isNewAssignment,
    ) {}

    public static function isExperimentActive(): bool
    {
        $until = (string) config('marathon_landing_copy.ab_test_until');

        return now()->lt(Carbon::parse($until)->startOfDay());
    }

    /**
     * Resolves the variant to render and, if this is a fresh assignment,
     * queues the sticky cookie on the outgoing response (works with a
     * plain View return -- AddQueuedCookiesToResponse attaches it).
     */
    public static function resolveForRequest(Request $request): self
    {
        if (! self::isExperimentActive()) {
            return new self(MarathonLandingCopy::variantKey(), false);
        }

        $cookieValue = strtolower((string) $request->cookie(self::COOKIE_NAME));
        if (in_array($cookieValue, ['a', 'b'], true)) {
            return new self($cookieValue, false);
        }

        $assigned = random_int(0, 1) === 0 ? 'a' : 'b';
        Cookie::queue(self::COOKIE_NAME, $assigned, self::COOKIE_MINUTES);

        return new self($assigned, true);
    }

    /**
     * Reads the visitor's sticky variant without assigning one -- used at
     * lead-creation time. Returns null for a visitor with no cookie (never
     * saw the live-test landing, or the experiment was already over when
     * they first arrived), so Lead::landing_copy_variant is never
     * fabricated.
     */
    public static function variantFromRequest(Request $request): ?string
    {
        $cookieValue = strtolower((string) $request->cookie(self::COOKIE_NAME));

        return in_array($cookieValue, ['a', 'b'], true) ? $cookieValue : null;
    }

    public static function recordImpression(string $variant): void
    {
        self::recordEvent($variant, 'impression');
    }

    public static function recordLead(string $variant): void
    {
        self::recordEvent($variant, 'lead');
    }

    private static function recordEvent(string $variant, string $type): void
    {
        MarathonLandingCopyVariantEvent::create([
            'variant' => $variant,
            'event_type' => $type,
        ]);
    }
}
