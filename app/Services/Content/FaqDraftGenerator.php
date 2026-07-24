<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCandidate;
use App\Models\Lesson;
use App\Services\Bot\CuratorAi;
use App\Services\Lecture\ClipSpanPlanner;
use App\Support\TranscriptParser;

/**
 * Mines a published lecture transcript for FAQ-shaped Q/A drafts (H1549,
 * Wave 3, ROADMAP "Wave 3", D3: lecture + timecode titles only — never
 * SupportTopic / CAI3 support gaps). CuratorAi refines the answer when an
 * OpenRouter key is set; a deterministic heuristic covers tests and no-key
 * deploys (same idiom as SocialDraftGenerator / SpanRanker).
 *
 * Body is always a short answer grounded in transcript sentences — never the
 * full paid lecture dump (D15 / VERIFICATION W4 D2 spirit applied early).
 */
final class FaqDraftGenerator
{
    public const MAX_DRAFTS_PER_LESSON = 5;

    public const MAX_ANSWER_CHARS = 480;

    public function __construct(private readonly CuratorAi $curatorAi) {}

    /**
     * @return list<ContentCandidate>
     */
    public function draftForLesson(Lesson $lesson): array
    {
        if (empty($lesson->transcript_file)) {
            return [];
        }

        $sentences = TranscriptParser::sentencesFromPublicFile($lesson->transcript_file);
        if ($sentences === []) {
            return [];
        }

        $pairs = $this->extractQuestionPairs($sentences);
        if ($pairs === []) {
            $pairs = $this->pairsFromSpans($lesson, $sentences);
        }

        $pairs = array_slice($pairs, 0, self::MAX_DRAFTS_PER_LESSON);
        $created = [];

        foreach ($pairs as $pair) {
            $title = $this->normalizeTitle($pair['question']);
            if ($title === '') {
                continue;
            }

            $existing = ContentCandidate::query()
                ->where('lesson_id', $lesson->id)
                ->where('type', ContentCandidate::TYPE_FAQ_DRAFT)
                ->where('title', $title)
                ->first();

            // Never overwrite staff-accepted / published drafts.
            if ($existing !== null && $existing->status !== ContentCandidate::STATUS_DRAFT) {
                $created[] = $existing;

                continue;
            }

            $fingerprint = substr(hash('sha256', mb_strtolower($title)), 0, 16);
            $body = $this->composeAnswer($title, $pair['answer'], (string) ($lesson->topic ?: $lesson->title));
            $quote = $this->shortQuote($pair['answer']);

            $attributes = [
                'status' => ContentCandidate::STATUS_DRAFT,
                'body' => $body,
                'quote' => $quote,
                'channel_target' => 'cabinet_faq',
                'meta' => [
                    'source' => 'lecture_transcript',
                    'fingerprint' => $fingerprint,
                    'start_seconds' => $pair['start'] ?? null,
                    'end_seconds' => $pair['end'] ?? null,
                    'generator' => empty(config('services.openrouter.api_key')) ? 'heuristic' : 'curator_ai_or_heuristic',
                ],
            ];

            if ($existing !== null) {
                $existing->fill($attributes)->save();
                $created[] = $existing->refresh();
            } else {
                $created[] = ContentCandidate::create(array_merge($attributes, [
                    'lesson_id' => $lesson->id,
                    'type' => ContentCandidate::TYPE_FAQ_DRAFT,
                    'title' => $title,
                ]));
            }
        }

        return $created;
    }

    /**
     * @param  list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>  $sentences
     * @return list<array{question:string,answer:string,start:?float,end:?float}>
     */
    private function extractQuestionPairs(array $sentences): array
    {
        $pairs = [];
        $count = count($sentences);

        for ($i = 0; $i < $count; $i++) {
            $text = trim((string) ($sentences[$i]['text'] ?? ''));
            if ($text === '' || ! str_contains($text, '?')) {
                continue;
            }

            $answerParts = [];
            for ($j = $i + 1; $j < $count && count($answerParts) < 2; $j++) {
                $next = trim((string) ($sentences[$j]['text'] ?? ''));
                if ($next === '') {
                    continue;
                }
                if (str_contains($next, '?') && mb_strlen($next) < 160) {
                    break;
                }
                $answerParts[] = $next;
            }

            if ($answerParts === []) {
                continue;
            }

            $pairs[] = [
                'question' => $text,
                'answer' => implode(' ', $answerParts),
                'start' => $sentences[$i]['start'] ?? null,
                'end' => $sentences[min($i + count($answerParts), $count - 1)]['end'] ?? null,
            ];
        }

        return $pairs;
    }

    /**
     * Fallback when the lecture has no interrogatives: turn ClipSpanPlanner
     * titles (AI-timecode packs) into FAQ-shaped prompts with span-local text.
     *
     * @param  list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>  $sentences
     * @return list<array{question:string,answer:string,start:?float,end:?float}>
     */
    private function pairsFromSpans(Lesson $lesson, array $sentences): array
    {
        $spans = ClipSpanPlanner::planSpans($lesson, targetSeconds: 90, maxSpans: self::MAX_DRAFTS_PER_LESSON);
        $pairs = [];

        foreach ($spans as $span) {
            $start = (float) $span['start_seconds'];
            $end = (float) $span['end_seconds'];
            $chunk = [];
            foreach ($sentences as $sentence) {
                if ($sentence['start'] >= $start && $sentence['start'] < $end) {
                    $chunk[] = trim((string) $sentence['text']);
                }
            }
            $answer = trim(implode(' ', array_filter($chunk)));
            if ($answer === '') {
                continue;
            }

            $snippet = mb_substr($answer, 0, 80);
            $pairs[] = [
                'question' => 'О чём этот фрагмент: «'.$snippet.'»?',
                'answer' => $answer,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $pairs;
    }

    private function composeAnswer(string $question, string $rawAnswer, string $topic): string
    {
        $trimmed = $this->capAnswer($rawAnswer);

        $apiKey = config('services.openrouter.api_key');
        if (! empty($apiKey)) {
            $generated = $this->generateViaCuratorAi($question, $trimmed, $topic);
            if ($generated !== null && $generated !== '') {
                return $this->capAnswer($generated);
            }
        }

        return $trimmed;
    }

    private function generateViaCuratorAi(string $question, string $evidence, string $topic): ?string
    {
        $prompt = 'Сформулируй короткий ответ FAQ (1–3 предложения, русский) на вопрос '.
            "«{$question}» по теме лекции «{$topic}». Опирайся только на этот фрагмент ".
            "транскрипта (не добавляй факты вне него):\n\n{$evidence}\n\n".
            'Без markdown, без приветствия — только полезный ответ.';

        return $this->curatorAi->chat([
            ['role' => 'system', 'content' => 'Ты редактор FAQ школы санскрита. Пишешь короткие ответы по транскрипту.'],
            ['role' => 'user', 'content' => $prompt],
        ]);
    }

    private function capAnswer(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= self::MAX_ANSWER_CHARS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX_ANSWER_CHARS - 1)).'…';
    }

    private function shortQuote(string $answer): ?string
    {
        $answer = trim($answer);
        if ($answer === '') {
            return null;
        }

        if (preg_match('/^[^.!?]+[.!?]/u', $answer, $m) === 1) {
            $quote = trim($m[0]);
        } else {
            $quote = mb_substr($answer, 0, 200);
        }

        try {
            QuotePolicy::assertPublicQuote($quote);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $quote;
    }

    private function normalizeTitle(string $question): string
    {
        $question = trim(preg_replace('/\s+/u', ' ', $question) ?? $question);
        if (mb_strlen($question) > 180) {
            $question = rtrim(mb_substr($question, 0, 179)).'…';
        }

        return $question;
    }
}
