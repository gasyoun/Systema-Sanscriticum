<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Lesson;

/**
 * Publish-on-lesson-publish trigger (H1547, Wave 1, IMPLEMENTATION step 6).
 * No existing Lesson-publish observer/listener was found (grepped
 * app/Observers + Filament resources) — this is a new one, model-level so it
 * fires regardless of which surface (Filament, API) flips `is_published`.
 *
 * Gated by `content_from_lectures` (this wave's master switch) AND
 * `clip_marketing` (H1452's own flag, unchanged) — both OFF by default, so
 * this is prod-inert until an explicit activation step.
 */
class LessonObserver
{
    public function updated(Lesson $lesson): void
    {
        // Only the become-published transition, not every unrelated save —
        // otherwise a lesson stuck published-without-clips-yet (n8n callback
        // still pending) would re-dispatch on its next unrelated edit too.
        if (! $lesson->wasChanged('is_published')) {
            return;
        }

        $this->maybeDispatchExtraction($lesson);
    }

    public function created(Lesson $lesson): void
    {
        $this->maybeDispatchExtraction($lesson);
    }

    private function maybeDispatchExtraction(Lesson $lesson): void
    {
        if (! config('features.content_from_lectures') || ! config('features.clip_marketing')) {
            return;
        }

        if (! $lesson->is_published || empty($lesson->transcript_file)) {
            return;
        }

        if (empty($lesson->video_url) && empty($lesson->youtube_url) && empty($lesson->rutube_url)) {
            return;
        }

        // Idempotent: never re-dispatch once clips already exist for this lesson.
        if ($lesson->clips()->exists()) {
            return;
        }

        DispatchLectureClipExtractionJob::dispatch($lesson->id);
    }
}
