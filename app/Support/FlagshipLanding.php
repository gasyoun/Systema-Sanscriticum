<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Course;

/**
 * Overlay for the three Gasuns flagships (H2761).
 *
 * Course already has landing slots (outcomes, audience, faqs, teacher bio).
 * This class only fills them when the row is empty — Filament wins.
 */
final class FlagshipLanding
{
    public const KEYS = ['kochergina', 'start_chteniya', 'buhler'];

    /** Representative prod slugs — tests and smoke use these three routes. */
    public const SMOKE_SLUGS = [
        'kochergina' => 'grammatika-po-kocerginoi-gr61',
        'start_chteniya' => 'start-chteniya',
        'buhler' => 'grammatika-po-biulleru-gr27',
    ];

    public static function keyFor(Course $course): ?string
    {
        $slug = mb_strtolower((string) $course->slug);
        $title = (string) $course->title;

        foreach (config('flagship_landing.programs', []) as $key => $program) {
            $match = $program['match'] ?? [];
            foreach ($match['slug_contains'] ?? [] as $needle) {
                if ($needle !== '' && str_contains($slug, mb_strtolower((string) $needle))) {
                    return (string) $key;
                }
            }
            foreach ($match['title_contains'] ?? [] as $needle) {
                if ($needle !== '' && mb_stripos($title, (string) $needle) !== false) {
                    return (string) $key;
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *     key: string,
     *     outcomes: list<string>,
     *     audience: list<string>,
     *     unfit: list<string>,
     *     faqs: list<array{q: string, a: string}>,
     *     teacher_bio_html: ?string,
     *     free_step: array{
     *         kind: string,
     *         eyebrow: string,
     *         title: string,
     *         body: string,
     *         cta_label: string,
     *         cta_url: string
     *     }
     * }|null
     */
    public static function for(Course $course): ?array
    {
        $key = self::keyFor($course);
        if ($key === null) {
            return null;
        }

        $program = config('flagship_landing.programs.'.$key);
        if (! is_array($program)) {
            return null;
        }

        $dbOutcomes = self::filledList($course->outcomes ?? []);
        $dbAudience = self::filledList($course->audience ?? []);
        $dbFaqs = $course->relationLoaded('faqs')
            ? $course->faqs->where('is_visible', true)->values()
            : collect();

        $faqs = $dbFaqs->isNotEmpty()
            ? $dbFaqs->map(fn ($faq) => [
                'q' => (string) $faq->question,
                'a' => (string) $faq->answer,
            ])->all()
            : array_values($program['faqs'] ?? []);

        $teacher = $course->teacher;
        $teacherBio = null;
        if ($teacher && ! filled($teacher->bio)) {
            $html = config('flagship_landing.teacher_bio_html');
            $teacherBio = is_string($html) && $html !== '' ? $html : null;
        }

        return [
            'key' => $key,
            'outcomes' => $dbOutcomes !== [] ? $dbOutcomes : array_values($program['outcomes'] ?? []),
            'audience' => $dbAudience !== [] ? $dbAudience : array_values($program['audience'] ?? []),
            'unfit' => array_values($program['unfit'] ?? []),
            'faqs' => $faqs,
            'teacher_bio_html' => $teacherBio,
            'free_step' => self::freeStep($program['free_step'] ?? [], $course),
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array{kind: string, eyebrow: string, title: string, body: string, cta_label: string, cta_url: string}
     */
    private static function freeStep(array $step, Course $course): array
    {
        $url = route('shop.course.show', $course->slug).'#tariffs';
        if (! empty($step['cta_route'])) {
            $url = route((string) $step['cta_route']);
        } elseif (! empty($step['cta_hash'])) {
            $url = route('shop.course.show', $course->slug).(string) $step['cta_hash'];
        }

        return [
            'kind' => (string) ($step['kind'] ?? 'evergreen'),
            'eyebrow' => (string) ($step['eyebrow'] ?? 'Первый шаг'),
            'title' => (string) ($step['title'] ?? ''),
            'body' => (string) ($step['body'] ?? ''),
            'cta_label' => (string) ($step['cta_label'] ?? 'Дальше'),
            'cta_url' => $url,
        ];
    }

    /**
     * @return list<string>
     */
    private static function filledList(mixed $items): array
    {
        return collect(is_array($items) ? $items : [])
            ->filter(fn ($item) => filled($item))
            ->map(fn ($item) => (string) $item)
            ->values()
            ->all();
    }
}
