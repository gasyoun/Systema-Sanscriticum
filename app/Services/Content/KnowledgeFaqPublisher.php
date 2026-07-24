<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCandidate;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

/**
 * Publishes an accepted faq_draft ContentCandidate into the cabinet-bot
 * knowledge layer (H1549 Wave 3 / CAI5 target pattern).
 *
 * Does NOT touch `resources/knowledge/faq.md` — that file is regenerated from
 * ORS-FAQ wiki and would wipe lecture entries on the next export. Lecture
 * Accepts land in a sibling file that BotKnowledgeBase also loads.
 */
final class KnowledgeFaqPublisher
{
    public const CACHE_KEY = 'bot.kb.faq';

    public static function path(): string
    {
        $configured = config('content.lecture_faq_path');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return resource_path('knowledge/faq_from_lectures.md');
    }

    /**
     * Append the draft to the knowledge file and mark the candidate published.
     * Idempotent: a second Accept with the same candidate id is a no-op append.
     */
    public function publish(ContentCandidate $candidate): bool
    {
        if ($candidate->type !== ContentCandidate::TYPE_FAQ_DRAFT) {
            return false;
        }

        if ($candidate->status !== ContentCandidate::STATUS_ACCEPTED
            && $candidate->status !== ContentCandidate::STATUS_PUBLISHED) {
            return false;
        }

        $title = trim((string) $candidate->title);
        $body = trim((string) $candidate->body);
        if ($title === '' || $body === '') {
            return false;
        }

        $path = self::path();
        $marker = "<!-- content_candidate:{$candidate->id} -->";
        $existing = is_file($path) ? (string) file_get_contents($path) : '';

        if (! str_contains($existing, $marker)) {
            $dir = dirname($path);
            if (! is_dir($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            if ($existing === '') {
                $existing = $this->header();
            }

            $block = "\n{$marker}\n### {$title}\n{$body}\n";
            File::put($path, rtrim($existing)."\n".$block);
        }

        Cache::forget(self::CACHE_KEY);

        if ($candidate->status !== ContentCandidate::STATUS_PUBLISHED) {
            $candidate->update([
                'status' => ContentCandidate::STATUS_PUBLISHED,
                'published_at' => now(),
                'channel_target' => $candidate->channel_target ?: 'cabinet_faq',
            ]);
        }

        return true;
    }

    private function header(): string
    {
        return <<<'MD'
# FAQ из лекций (АСР)

<!-- Lecture-sourced entries appended by KnowledgeFaqPublisher on faq_draft
     Accept (H1549 Wave 3). Sibling of the ORS-export faq.md — do not merge
     into the export pipeline. BotKnowledgeBase loads both. -->

MD;
    }
}
