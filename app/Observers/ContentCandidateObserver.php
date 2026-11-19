<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\PublishSocialPostJob;
use App\Jobs\SendContentOneShotMailJob;
use App\Models\ContentCandidate;
use App\Services\Content\SocialDraftGenerator;

/**
 * Chains Wave 2–3 (H1548/H1549) off Wave 1's own "accepted" transition:
 *
 * - clip candidate accepted (staff marked the LectureClip free) → draft a
 *   social_post candidate (SocialDraftGenerator). Idempotent: draftForClip
 *   upserts on (parent_id, type), so re-saving the same clip never
 *   duplicates the draft.
 * - social_post candidate accepted (staff reviewed the draft in Filament) →
 *   dispatch PublishSocialPostJob.
 * - email_blast candidate accepted (staff reviewed the weekly digest) →
 *   dispatch SendContentOneShotMailJob.
 * - study_artifact / article: Accept is editorial only — no dispatch
 *   (H1550/H1551; VERIFICATION E2: study never on pilot publish).
 *
 * Social/mail jobs are themselves the flag gate (content_auto_publish_pilot /
 * content_email_oneshot, early-return when OFF) — dispatching unconditionally
 * here mirrors LessonObserver's own flag-in-the-job style.
 */
class ContentCandidateObserver
{
    public function __construct(
        private readonly SocialDraftGenerator $socialDraftGenerator,
    ) {}

    public function updated(ContentCandidate $candidate): void
    {
        $this->handleIfAccepted($candidate);
    }

    public function created(ContentCandidate $candidate): void
    {
        // Edge case only — the normal Wave 1 flow always creates a clip
        // candidate as draft first, then updates it to accepted (see class
        // docblock). Handled for symmetry, e.g. a candidate seeded pre-accepted.
        $this->handleIfAccepted($candidate);
    }

    private function handleIfAccepted(ContentCandidate $candidate): void
    {
        if (! $candidate->wasChanged('status') || $candidate->status !== ContentCandidate::STATUS_ACCEPTED) {
            return;
        }

        if ($candidate->type === ContentCandidate::TYPE_CLIP) {
            $this->socialDraftGenerator->draftForClip($candidate);

            return;
        }

        if ($candidate->type === ContentCandidate::TYPE_SOCIAL_POST) {
            PublishSocialPostJob::dispatch($candidate->id);

            return;
        }

        if ($candidate->type === ContentCandidate::TYPE_EMAIL_BLAST) {
            SendContentOneShotMailJob::dispatch($candidate->id);

            return;
        }

        // TYPE_FAQ_DRAFT / TYPE_STUDY_ARTIFACT / TYPE_ARTICLE: intentional
        // no-op (staff review only). The lecture FAQ band was retired 18-10-2026
        // (RAG grill card 6): lecture material enters as lesson chunks, never as
        // hand-published FAQ prose.
    }
}
