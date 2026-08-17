<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * H2168 — which reading packs belong to which cohort COURSE, and how to read one.
 *
 * H2110 made the reader multi-pack for the single «Старт чтения» cohort: a flat
 * list of slugs (`ReadingPackController::COHORT_PACKS`) gated by
 * `StartChteniyaCohort::hasEntitlement()`. That shape cannot express two courses
 * sold separately, so this class adds the missing dimension — course-slug ->
 * ordered pack slugs — on top of H2164's `config/cohort_courses.php` registry
 * and its `CourseCohortEntitlement::hasEntitlement($user, $courseSlug)` gate.
 *
 * Deliberately ADDITIVE. `StartChteniyaCohort`, `config/start_chteniya.php`, the
 * `features.kosha_reader` public demo route and H2110's in-cabinet
 * `/dvaram/reading[/{slug}]` routes are untouched: the course routes are a
 * second path with their own per-course deploy switch
 * (`cohort_courses.<slug>.enabled`, OFF by default), so with the new path OFF
 * the old surfaces render exactly what they rendered before.
 *
 * Data provenance: every feed here is a VERBATIM copy of kosha's H2165 freeze
 * (`data/nala_subhashita/MANIFEST.json`), vendored + sha256-verified by
 * `scripts/vendor_nala_subhashita_packs.py`. `subhashita-beginner` carries the
 * freeze's documented schema delta and is normalised at read time by
 * `SubhashitaReadingPackAdapter` — no second schema, no rewritten pin.
 *
 * Nala unlock order (H2168 ambiguity call, H2170's transcript analysis had not
 * landed): the three packs are listed and numbered in NARRATIVE order
 * (MBh 3.50 -> 3.51 -> 3.52) and an entitled student may open any of them. A
 * hard sequential LOCK is deliberately not invented here — the only completion
 * signal that could drive one is the tap-token/lookup telemetry, which this
 * handoff's fence excludes ("reading-pack RENDER only"). The order is the
 * durable half of the decision; enforcement waits for H2170.
 */
final class CourseReadingPacks
{
    /** Where the H2165 freeze copies live, relative to resource_path(). */
    private const FEED_DIR = 'data/nala_subhashita';

    /**
     * pack slug -> [filename in FEED_DIR, adapter or null when already reading_pack_v1].
     *
     * Never resolve a slug from the URL into a path directly: only packs named
     * here are readable, so a traversal attempt is a 404 and not a file read.
     * `nala-1` here is the FREEZE copy under FEED_DIR; the older public demo
     * feed (`resources/data/kosha_reading_pack_nala_1.json`) keeps its own
     * historical path inside ReadingPackController and is not touched.
     *
     * @var array<string,array{0:string,1:?class-string}>
     */
    private const FEEDS = [
        'nala-1' => ['nala-1.json', null],
        'nala-2' => ['nala-2.json', null],
        'nala-3' => ['nala-3.json', null],
        'subhashita-beginner' => ['subhashita_beginner_pack.json', SubhashitaReadingPackAdapter::class],
    ];

    /**
     * The course's packs, in course order. Empty for an unknown/unconfigured
     * course, and never includes a slug this class cannot actually read.
     *
     * @return list<string>
     */
    public static function packSlugs(string $course): array
    {
        $packs = config("cohort_courses.{$course}.packs");
        if (! is_array($packs)) {
            return [];
        }

        return array_values(array_filter(
            $packs,
            fn ($slug): bool => is_string($slug) && isset(self::FEEDS[$slug]),
        ));
    }

    /** True only for a pack this course actually offers. */
    public static function offers(string $course, string $slug): bool
    {
        return in_array($slug, self::packSlugs($course), true);
    }

    /** Human course title for the cabinet heading; falls back to the config key. */
    public static function courseTitle(string $course): string
    {
        $title = config("cohort_courses.{$course}.title");

        return is_string($title) && $title !== '' ? $title : $course;
    }

    /**
     * Read one pack as reading_pack_v1, adapting the frozen shape when the
     * freeze says the shape differs.
     *
     * @return array<string,mixed>
     */
    public static function read(string $slug): array
    {
        if (! isset(self::FEEDS[$slug])) {
            throw new RuntimeException("Unknown course reading pack [{$slug}]");
        }

        [$filename, $adapter] = self::FEEDS[$slug];
        $path = resource_path(self::FEED_DIR.'/'.$filename);

        if (! is_file($path)) {
            throw new RuntimeException("Feed not found at {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException("Invalid or malformed feed at {$path}");
        }

        if ($adapter !== null) {
            /** @var array<string,mixed> $data */
            $data = $adapter::toReadingPackV1($data);
        }

        if (! isset($data['sentences'])) {
            throw new RuntimeException('Invalid or malformed reading pack at '.$path);
        }

        return $data;
    }
}
