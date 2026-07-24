<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCandidate;
use App\Models\LectureClip;

/**
 * Mirrors a LectureClip into a ContentCandidate type=clip row (H1547,
 * Wave 1). Runs regardless of `features.content_from_lectures` — this is a
 * cheap idempotent upsert, not a publish action; the flag only gates the
 * *generators* (waves 2-5) and the Filament surface's visibility.
 */
final class ContentCandidateSync
{
    public static function syncClip(LectureClip $clip): ContentCandidate
    {
        return ContentCandidate::updateOrCreate(
            ['lecture_clip_id' => $clip->id],
            [
                'type' => ContentCandidate::TYPE_CLIP,
                'lesson_id' => $clip->lesson_id,
                'title' => $clip->title,
                // Staff marking a clip free (LectureClipResource ToggleColumn)
                // is the Wave-1 "accept" signal — it's the only editorial gate
                // this wave has; waves 2+ get their own Filament Accept action.
                'status' => $clip->is_free ? ContentCandidate::STATUS_ACCEPTED : ContentCandidate::STATUS_DRAFT,
                'channel_target' => $clip->is_free ? 'vk_wall' : null,
                'meta' => [
                    'start_seconds' => $clip->start_seconds,
                    'end_seconds' => $clip->end_seconds,
                    'vk_video_id' => $clip->vk_video_id,
                    'vk_owner_id' => $clip->vk_owner_id,
                    'is_free' => $clip->is_free,
                ],
            ],
        );
    }
}
