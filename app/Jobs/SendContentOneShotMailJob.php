<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\ContentDigestMail;
use App\Models\ContentCandidate;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * One-shot send of an accepted `email_blast` ContentCandidate (H1548, Wave 2,
 * D14) to the existing newsletter_subscribe segment (H324) — no new campaign
 * domain model. Gated by `content_email_oneshot`, OFF until the August SMTP
 * activation (depends on the H1449 path / #504).
 */
final class SendContentOneShotMailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $contentCandidateId)
    {
        if (config('queue.default') === 'redis') {
            $this->onConnection('redis-long');
        }
        $this->onQueue('mailing');
    }

    public function uniqueId(): string
    {
        return (string) $this->contentCandidateId;
    }

    public function handle(): void
    {
        if (! config('features.content_email_oneshot')) {
            Log::info('SendContentOneShotMailJob: content_email_oneshot выключен — пропуск.', [
                'content_candidate_id' => $this->contentCandidateId,
            ]);

            return;
        }

        $candidate = ContentCandidate::find($this->contentCandidateId);
        if ($candidate === null || $candidate->type !== ContentCandidate::TYPE_EMAIL_BLAST) {
            return;
        }

        // Idempotent: only send an accepted draft, never twice.
        if ($candidate->status !== ContentCandidate::STATUS_ACCEPTED) {
            return;
        }

        $recipients = User::query()->whereNotNull('newsletter_subscribed_at')->pluck('email');
        if ($recipients->isEmpty()) {
            Log::warning('SendContentOneShotMailJob: сегмент newsletter_subscribed_at пуст — пропуск.', [
                'content_candidate_id' => $candidate->id,
            ]);

            return;
        }

        foreach ($recipients as $email) {
            Mail::to($email)->queue(new ContentDigestMail($candidate->title ?? 'Дайджест', $candidate->body ?? ''));
        }

        $candidate->update([
            'status' => ContentCandidate::STATUS_PUBLISHED,
            'published_at' => now(),
            'meta' => array_merge($candidate->meta ?? [], ['recipient_count' => $recipients->count()]),
        ]);
    }
}
