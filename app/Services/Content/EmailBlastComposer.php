<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCandidate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Composes a weekly `email_blast` ContentCandidate from the free clips
 * accepted that week (H1548, Wave 2, D14 "minimal one-shot mailer"). No new
 * campaign domain model beyond the candidate itself — SendContentOneShotMailJob
 * only ever sends a pre-composed, pre-accepted draft.
 */
final class EmailBlastComposer
{
    public function composeWeekly(?Carbon $referenceDate = null): ?ContentCandidate
    {
        $reference = $referenceDate ?? now();
        $windowStart = $reference->copy()->subDays(7);
        $weekLabel = $reference->format('o-\WW');

        $clips = ContentCandidate::query()
            ->where('type', ContentCandidate::TYPE_CLIP)
            ->where('status', ContentCandidate::STATUS_ACCEPTED)
            ->where('created_at', '>=', $windowStart)
            ->with('lesson')
            ->orderBy('created_at')
            ->get();

        if ($clips->isEmpty()) {
            return null;
        }

        return ContentCandidate::updateOrCreate(
            ['type' => ContentCandidate::TYPE_EMAIL_BLAST, 'title' => "Дайджест недели {$weekLabel}"],
            [
                'body' => $this->composeBody($clips),
                'channel_target' => 'email',
                'meta' => [
                    'week' => $weekLabel,
                    'clip_candidate_ids' => $clips->pluck('id')->all(),
                ],
            ],
        );
    }

    /**
     * @param  Collection<int, ContentCandidate>  $clips
     */
    private function composeBody(Collection $clips): string
    {
        $lines = ['Бесплатные фрагменты лекций за неделю:', ''];

        foreach ($clips as $clip) {
            $lessonTitle = $clip->lesson?->title;
            $lines[] = '— '.$clip->title.($lessonTitle !== null ? " ({$lessonTitle})" : '');
        }

        return implode("\n", $lines);
    }
}
