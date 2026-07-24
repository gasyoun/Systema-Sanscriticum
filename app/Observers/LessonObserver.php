<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\DispatchLectureClipExtractionJob;
use App\Models\Lesson;
use App\Services\Content\ArticleDraftGenerator;
use App\Services\Content\FaqDraftGenerator;
use App\Services\Content\StudyArtifactGenerator;

/**
 * Publish-on-lesson-publish trigger (H1547 Wave 1 + H1549 Wave 3 + H1550 Wave 4
 * + H1551 Wave 5). Model-level so it fires regardless of which surface
 * (Filament, API) flips `is_published`.
 *
 * Clip extraction: gated by `content_from_lectures` AND `clip_marketing`
 * (both OFF by default). FAQ / article / study drafts: gated by
 * `content_from_lectures` alone — draft-only; Filament Accept is the staff
 * gate (FAQ → knowledge; article/study stay editorial — study never on pilot
 * auto-publish).
 */
class LessonObserver
{
    public function __construct(
        private readonly FaqDraftGenerator $faqDraftGenerator,
        private readonly ArticleDraftGenerator $articleDraftGenerator,
        private readonly StudyArtifactGenerator $studyArtifactGenerator,
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
        $this->maybeDraftStudy($lesson);
    }

    public function created(Lesson $lesson): void
    {
        $this->maybeDispatchExtraction($lesson);
        $this->maybeDraftFaq($lesson);
        $this->maybeDraftArticle($lesson);
        $this->maybeDraftStudy($lesson);
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

    private function maybeDraftStudy(Lesson $lesson): void
    {
        if (! config('features.content_from_lectures')) {
            return;
        }

        if (! $lesson->is_published || empty($lesson->transcript_file)) {
            return;
        }

        $this->studyArtifactGenerator->draftForLesson($lesson);
    }
}
