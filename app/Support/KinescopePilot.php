<?php

declare(strict_types=1);

namespace App\Support;

/**
 * H1451 — Wave 3 Kinescope pilot scope (one flagship course only).
 *
 * Gate: features.kinescope_pilot + config video.kinescope_pilot_course_id.
 * When inactive, the lesson player must behave exactly as before (YouTube/RuTube only).
 *
 * Executor: Grok 4.5 (grok-4.5) via xAI (Sonnet-lock override; true-redo 24-07-2026).
 */
final class KinescopePilot
{
    public static function isActiveForCourse(?int $courseId): bool
    {
        if (! config('features.kinescope_pilot', false)) {
            return false;
        }

        $pilotId = config('video.kinescope_pilot_course_id');
        if ($pilotId === null || $pilotId === '' || $courseId === null) {
            return false;
        }

        return (int) $pilotId === (int) $courseId;
    }

    /**
     * First lesson field that holds a Kinescope URL (staff sometimes paste into
     * youtube_url / rutube_url by habit). Prefer video_url, then youtube_url, then rutube_url.
     *
     * @param  object{video_url?: ?string, youtube_url?: ?string, rutube_url?: ?string}  $lesson
     */
    public static function candidateUrlFromLesson(object $lesson): ?string
    {
        foreach (['video_url', 'youtube_url', 'rutube_url'] as $field) {
            $url = isset($lesson->{$field}) ? (string) $lesson->{$field} : '';
            if ($url !== '' && VideoEmbed::isKinescope($url)) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Kinescope iframe src for a lesson URL when the pilot is active for this course.
     * Returns null when flag off, course not the pilot, or URL is not Kinescope.
     */
    public static function embedForCourse(?string $url, ?int $courseId): ?string
    {
        if (! self::isActiveForCourse($courseId)) {
            return null;
        }

        if (! VideoEmbed::isKinescope($url)) {
            return null;
        }

        return VideoEmbed::embed($url);
    }

    /**
     * Convenience: gate + multi-field resolve for a lesson on a course.
     *
     * @param  object{video_url?: ?string, youtube_url?: ?string, rutube_url?: ?string}  $lesson
     */
    public static function embedForLesson(object $lesson, ?int $courseId): ?string
    {
        return self::embedForCourse(self::candidateUrlFromLesson($lesson), $courseId);
    }
}
