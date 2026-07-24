<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Lesson;
use App\Services\Content\ArticleDraftGenerator;
use App\Services\Content\FaqDraftGenerator;

/**
 * Publish-on-lesson-publish trigger (H1547 Wave 1 + H1549 Wave 3 + H1550 Wave 4).
 * Model-level so it fires regardless of which surface (Filament, API)
 * flips `is_published`.
 *
 * Clip extraction: gated by `content_from_lectures` AND `clip_marketing`
 * (both OFF by default). FAQ drafts (Wave 3) and article drafts (Wave 4):
 * gated by `content_from_lectures` alone — draft-only; Filament Accept is
 * the staff gate (FAQ → knowledge; article stays editorial draft).
 */
class LessonObserver
{
    public function __construct(
        private readonly FaqDraftGenerator $faqDraftGenerator,
        private readonly ArticleDraftGenerator $articleDraftGenerator,
    ) {}

    public function updated(Lesson $lesson): void
    {
        // Only the become-published transition, not every unrelated save —
        // otherwise a lesson stuck published-without-clips-yet (n8n callback
        // still pending) would re-dispatch on its next unrelated edit too.
        if (! $lesson->wasChanged('is_published')) {
            return;
        }

        $this->maybeDispatchExtraction($lesson);
        $this->maybeDraftFaq($lesson);
        $this->maybeDraftArticle($lesson);
    }

    public function created(Lesson $lesson): void
    {
        $this->maybeDispatchExtraction($lesson);
        $this->maybeDraftFaq($lesson);
        $this->maybeDraftArticle($lesson);
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

    private function maybeDraftFaq(Lesson $lesson): void
    {
        if (! config('features.content_from_lectures')) {
            return;
        }

        if (! $lesson->is_published || empty($lesson->transcript_file)) {
            return;
        }

        $this->faqDraftGenerator->draftForLesson($lesson);
    }

    private function maybeDraftArticle(Lesson $lesson): void
    {
        if (! config('features.content_from_lectures')) {
            return;
        }

        if (! $lesson->is_published || empty($lesson->transcript_file)) {
            return;
        }

        $this->articleDraftGenerator->draftForLesson($lesson);
    }
}
