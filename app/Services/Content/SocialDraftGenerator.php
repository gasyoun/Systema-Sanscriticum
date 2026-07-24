<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCandidate;
use App\Models\Lesson;
use App\Services\Bot\CuratorAi;
use App\Support\TranscriptParser;

/**
 * Drafts a social_post ContentCandidate for a free clip (H1548, Wave 2,
 * ROADMAP §"Wave 2" step 1, D13: one VK wall post per free clip, TG mirror).
 * The quote is grounded in the actual transcript sentence inside the clip's
 * own [start_seconds, end_seconds) span — never invented, never the full
 * lecture body (D15). CuratorAi turns that into a short blurb when an
 * OpenRouter key is configured; a deterministic template covers the
 * no-key/test path, same idiom as SpanRanker's heuristic default.
 */
final class SocialDraftGenerator
{
    public function __construct(private readonly CuratorAi $curatorAi) {}

    public function draftForClip(ContentCandidate $clipCandidate): ?ContentCandidate
    {
        if ($clipCandidate->type !== ContentCandidate::TYPE_CLIP) {
            return null;
        }

        // Only free (staff-accepted) clips get a public social draft (D13).
        if ($clipCandidate->status !== ContentCandidate::STATUS_ACCEPTED) {
            return null;
        }

        $lesson = $clipCandidate->lesson;
        if ($lesson === null) {
            return null;
        }

        $meta = $clipCandidate->meta ?? [];
        $quote = $this->extractQuote($lesson, $meta['start_seconds'] ?? null, $meta['end_seconds'] ?? null);
        $body = $this->composeBlurb((string) $clipCandidate->title, (string) ($lesson->topic ?: $lesson->title), $quote);

        return ContentCandidate::updateOrCreate(
            ['parent_id' => $clipCandidate->id, 'type' => ContentCandidate::TYPE_SOCIAL_POST],
            [
                'lesson_id' => $lesson->id,
                'lecture_clip_id' => $clipCandidate->lecture_clip_id,
                'title' => $clipCandidate->title,
                'body' => $body,
                'quote' => $quote,
                'channel_target' => 'vk_wall',
                'meta' => ['tg_mirror' => true],
            ],
        );
    }

    /**
     * The first transcript sentence starting inside the clip's span — a
     * single sentence is always QuotePolicy-compliant (≤1 sentence ender),
     * so no heuristic can accidentally produce a hard-fail draft.
     */
    private function extractQuote(Lesson $lesson, mixed $start, mixed $end): ?string
    {
        if (! is_numeric($start) || ! is_numeric($end)) {
            return null;
        }

        $sentences = TranscriptParser::sentencesFromPublicFile($lesson->transcript_file);
        foreach ($sentences as $sentence) {
            if ($sentence['start'] >= (float) $start && $sentence['start'] < (float) $end) {
                $text = trim((string) ($sentence['text'] ?? ''));

                return $text !== '' ? $text : null;
            }
        }

        return null;
    }

    private function composeBlurb(string $title, string $topic, ?string $quote): string
    {
        $apiKey = config('services.openrouter.api_key');
        if (! empty($apiKey)) {
            $generated = $this->generateViaCuratorAi($title, $topic, $quote);
            if ($generated !== null) {
                return $generated;
            }
        }

        $lines = ["🎬 {$title}", $topic];
        if ($quote !== null) {
            $lines[] = "«{$quote}»";
        }
        $lines[] = 'Смотрите отрывок и записывайтесь на курс — ссылка в сообществе.';

        return implode("\n\n", $lines);
    }

    private function generateViaCuratorAi(string $title, string $topic, ?string $quote): ?string
    {
        $prompt = 'Напиши короткий анонс для соцсетей (ВК/Телеграм) по бесплатному '.
            "видео-фрагменту лекции \"{$title}\" (тема: {$topic}).".
            ($quote !== null ? " Можно один раз процитировать: «{$quote}»." : '').
            ' Не более 3 коротких абзацев, без markdown, без хэштегов, дружелюбный тон.';

        return $this->curatorAi->chat([
            ['role' => 'system', 'content' => 'Ты — маркетолог школы санскрита, пишешь анонсы для соцсетей.'],
            ['role' => 'user', 'content' => $prompt],
        ]);
    }
}
