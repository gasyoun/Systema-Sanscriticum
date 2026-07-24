<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\LectureClip;
use App\Services\Content\ContentCandidateSync;

/**
 * Keeps ContentCandidate type=clip in sync with LectureClip (H1547, Wave 1,
 * IMPLEMENTATION step 7) — created by the n8n callback, updated when staff
 * toggles `is_free` in Filament.
 */
class LectureClipObserver
{
    public function created(LectureClip $clip): void
    {
        ContentCandidateSync::syncClip($clip);
    }

    public function updated(LectureClip $clip): void
    {
        ContentCandidateSync::syncClip($clip);
    }
}
