<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ContentCandidate;
use App\Services\Content\QuotePolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Posts one accepted social_post ContentCandidate to VK wall (with the free
 * clip's video attached) + a Telegram channel mirror (H1548, Wave 2, D13).
 * Same webhook-forward shape as PostMonthlySchedule/DispatchLectureClip-
 * ExtractionJob — Laravel never calls the VK/TG APIs directly, n8n does the
 * actual send.
 *
 * Prod-inert while `features.content_auto_publish_pilot` is OFF (default) —
 * early return, no HTTP, no real post ever fires from a test/CI run.
 */
final class PublishSocialPostJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public readonly int $contentCandidateId) {}

    public function handle(): void
    {
        if (! config('features.content_auto_publish_pilot')) {
            Log::info('PublishSocialPostJob: content_auto_publish_pilot выключен — пропуск.', [
                'content_candidate_id' => $this->contentCandidateId,
            ]);

            return;
        }

        $candidate = ContentCandidate::find($this->contentCandidateId);
        if ($candidate === null || $candidate->type !== ContentCandidate::TYPE_SOCIAL_POST) {
            return;
        }

        // Idempotent: never re-post an already-published candidate, and only
        // ever post one that staff explicitly accepted in Filament.
        if ($candidate->status !== ContentCandidate::STATUS_ACCEPTED) {
            return;
        }

        try {
            QuotePolicy::assertPublicQuote($candidate->quote);
        } catch (InvalidArgumentException $e) {
            $candidate->update([
                'status' => ContentCandidate::STATUS_FAILED,
                'meta' => array_merge($candidate->meta ?? [], ['error' => $e->getMessage()]),
            ]);
            Log::error('PublishSocialPostJob: QuotePolicy hard-fail — не публикуем.', [
                'content_candidate_id' => $candidate->id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $webhook = config('services.n8n.social_post_webhook');
        if (empty($webhook)) {
            Log::warning('PublishSocialPostJob: N8N_SOCIAL_POST_WEBHOOK не задан — пропуск.', [
                'content_candidate_id' => $candidate->id,
            ]);

            return;
        }

        $clip = $candidate->lectureClip;

        $response = Http::withHeaders([
            'X-Webhook-Secret' => (string) config('services.n8n.social_post_secret'),
        ])->timeout(15)->post($webhook, [
            'action' => 'social_post',
            'content_candidate_id' => $candidate->id,
            'lesson_id' => $candidate->lesson_id,
            'text_vk' => $candidate->body,
            // D13: TG mirror — same text, one webhook call posts both.
            'text_tg' => $candidate->body,
            'vk_video_id' => $clip?->vk_video_id,
            'vk_owner_id' => $clip?->vk_owner_id,
        ]);

        if ($response->successful()) {
            $candidate->update([
                'status' => ContentCandidate::STATUS_PUBLISHED,
                'published_at' => now(),
            ]);

            return;
        }

        Log::error('PublishSocialPostJob: n8n вернул '.$response->status(), [
            'content_candidate_id' => $candidate->id,
            'body' => $response->body(),
        ]);
    }
}
