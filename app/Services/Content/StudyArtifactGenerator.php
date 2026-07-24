<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCandidate;
use App\Models\Lesson;
use App\Services\Bot\CuratorAi;
use App\Services\Lecture\ClipSpanPlanner;
use App\Support\TranscriptParser;

/**
 * Drafts staff-first study materials from a lecture transcript (H1551, Wave 5,
 * ROADMAP "Wave 5"): summary, flashcard seed list, homework prompts.
 *
 * Three ContentCandidate rows per lesson (meta.kind = summary | card_seeds |
 * homework), type=study_artifact, channel_target=staff_study. Never on the
 * pilot auto-publish path (PublishSocialPostJob / SendContentOneShotMailJob
 * type-guard only social_post / email_blast). Accept in Filament is editorial
 * only — no knowledge write, no public channel, no full-transcript dump (D15).
 *
 * CuratorAi may polish when OpenRouter is set; heuristic spans cover tests and
 * no-key deploys (same idiom as ArticleDraftGenerator / FaqDraftGenerator).
 */
final class StudyArtifactGenerator
{
    public const KIND_SUMMARY = 'summary';

    public const KIND_CARD_SEEDS = 'card_seeds';

    public const KIND_HOMEWORK = 'homework';

    public const MAX_SUMMARY_CHARS = 900;

    public const MAX_CARD_SEEDS = 8;

    public const MAX_HOMEWORK_PROMPTS = 4;

    public const MAX_BODY_CHARS = 2400;

    /** Body must stay under this fraction of full transcript text. */
    public const MAX_BODY_RATIO = 0.18;

    public const CHANNEL_STAFF_STUDY = 'staff_study';

    public function __construct(private readonly CuratorAi $curatorAi) {}

    /**
     * Idempotent: upserts three draft study_artifact candidates for the lesson.
     *
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

        $sourceText = $this->joinSentenceText($sentences);
        $topic = (string) ($lesson->topic ?: $lesson->title ?: 'Лекция #'.$lesson->id);
        $spans = $this->spanChunks($lesson, $sentences);

        $sourceChars = mb_strlen($sourceText);
        $created = [];
        $created[] = $this->upsertKind(
            lesson: $lesson,
            kind: self::KIND_SUMMARY,
            title: $this->normalizeTitle('Конспект · '.$topic),
            body: $this->buildSummaryBody($topic, $spans, $sourceText),
            quote: $this->shortQuote($spans[0]['text'] ?? $sourceText),
            metaExtra: [
                'section_count' => count($spans),
                'source_chars' => $sourceChars,
            ],
        );
        $created[] = $this->upsertKind(
            lesson: $lesson,
            kind: self::KIND_CARD_SEEDS,
            title: $this->normalizeTitle('Карточки · '.$topic),
            body: $this->buildCardSeedsBody($topic, $spans, $sourceText),
            quote: null,
            metaExtra: [
                'card_count' => min(self::MAX_CARD_SEEDS, max(1, count($spans))),
                'source_chars' => $sourceChars,
            ],
        );
        $created[] = $this->upsertKind(
            lesson: $lesson,
            kind: self::KIND_HOMEWORK,
            title: $this->normalizeTitle('Домашнее задание · '.$topic),
            body: $this->buildHomeworkBody($topic, $spans, $sourceText),
            quote: null,
            metaExtra: [
                'prompt_count' => self::MAX_HOMEWORK_PROMPTS,
                'source_chars' => $sourceChars,
            ],
        );

        return array_values(array_filter($created));
    }

    /**
     * @param  list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>  $sentences
     * @return list<array{heading:string,text:string}>
     */
    private function spanChunks(Lesson $lesson, array $sentences): array
    {
        $spans = ClipSpanPlanner::planSpans($lesson, targetSeconds: 90, maxSpans: self::MAX_CARD_SEEDS);
        $chunks = [];

        foreach ($spans as $i => $span) {
            $start = (float) $span['start_seconds'];
            $end = (float) $span['end_seconds'];
            $parts = [];
            foreach ($sentences as $sentence) {
                if ($sentence['start'] >= $start && $sentence['start'] < $end) {
                    $parts[] = trim((string) $sentence['text']);
                }
            }
            $text = $this->capText(trim(implode(' ', array_filter($parts))), 320);
            if ($text === '') {
                continue;
            }
            $heading = trim((string) ($span['title'] ?? ''));
            if ($heading === '') {
                $heading = 'Фрагмент '.($i + 1);
            }
            $chunks[] = ['heading' => $heading, 'text' => $text];
        }

        if ($chunks !== []) {
            return $chunks;
        }

        // Fallback: equal sentence buckets when no AI timecodes / planner empty.
        $total = count($sentences);
        $bucket = max(1, (int) ceil($total / min(self::MAX_CARD_SEEDS, max(1, $total))));
        $n = 1;
        for ($i = 0; $i < $total && count($chunks) < self::MAX_CARD_SEEDS; $i += $bucket) {
            $slice = array_slice($sentences, $i, $bucket);
            $text = $this->capText($this->joinSentenceText($slice), 320);
            if ($text === '') {
                continue;
            }
            $chunks[] = ['heading' => 'Часть '.$n, 'text' => $text];
            $n++;
        }

        return $chunks;
    }

    /**
     * @param  list<array{heading:string,text:string}>  $spans
     */
    private function buildSummaryBody(string $topic, array $spans, string $sourceText): string
    {
        $apiKey = config('services.openrouter.api_key');
        if (! empty($apiKey)) {
            $outline = [];
            foreach (array_slice($spans, 0, 5) as $span) {
                $outline[] = '- '.$span['heading'].': '.$span['text'];
            }
            $evidence = implode("\n", $outline);
            $generated = $this->curatorAi->chat([
                ['role' => 'system', 'content' => 'Ты методист школы санскрита. Пишешь краткие конспекты лекций для staff review.'],
                ['role' => 'user', 'content' => "Краткий конспект (markdown, 4–8 пунктов, русский) по теме «{$topic}». Только по фрагментам:\n\n{$evidence}\n\nБез полной расшифровки, без HTML."],
            ]);
            if (is_string($generated) && trim($generated) !== '') {
                return $this->enforceBodyCaps($this->normalizeBody($generated), $sourceText, self::MAX_SUMMARY_CHARS);
            }
        }

        $parts = ["# Конспект: {$topic}", '', 'Краткие тезисы по стенограмме (не полная расшифровка):', ''];
        foreach (array_slice($spans, 0, 6) as $span) {
            $parts[] = '- **'.$span['heading'].'.** '.$this->firstSentence($span['text']);
        }
        $parts[] = '';
        $parts[] = '_Черновик для staff review — Wave 5 study_artifact._';

        return $this->enforceBodyCaps($this->normalizeBody(implode("\n", $parts)), $sourceText, self::MAX_SUMMARY_CHARS);
    }

    /**
     * @param  list<array{heading:string,text:string}>  $spans
     */
    private function buildCardSeedsBody(string $topic, array $spans, string $sourceText): string
    {
        $cards = [];
        foreach (array_slice($spans, 0, self::MAX_CARD_SEEDS) as $i => $span) {
            $front = 'О чём фрагмент «'.$span['heading'].'»?';
            $back = $this->firstSentence($span['text']);
            if ($back === '') {
                $back = $this->capText($span['text'], 160);
            }
            $cards[] = ($i + 1).'. **Q:** '.$front."\n   **A:** ".$back;
        }

        if ($cards === []) {
            $cards[] = '1. **Q:** О чём лекция «'.$topic.'»?'."\n   **A:** См. конспект staff.";
        }

        $body = $this->normalizeBody(
            "# Карточки: {$topic}\n\n".
            "Сиды для flashcard/SRS (staff review; не публикуются автоматически):\n\n".
            implode("\n\n", $cards)
        );

        return $this->enforceBodyCaps($body, $sourceText);
    }

    /**
     * @param  list<array{heading:string,text:string}>  $spans
     */
    private function buildHomeworkBody(string $topic, array $spans, string $sourceText): string
    {
        $prompts = [
            'Перескажите своими словами главный тезис лекции «'.$topic.'» (3–5 предложений).',
            'Выпишите 5 терминов/форм из лекции и дайте краткое определение каждой.',
            'Составьте 3 вопроса, которые вы бы задали преподавателю по материалу «'.$topic.'».',
            'Приведите один пример (из лекции или свой) и объясните, как он иллюстрирует тему.',
        ];

        if (isset($spans[0]['heading'])) {
            $prompts[1] = 'По фрагменту «'.$spans[0]['heading'].'»: выпишите 5 терминов/форм и краткие определения.';
        }

        $prompts = array_slice($prompts, 0, self::MAX_HOMEWORK_PROMPTS);
        $lines = [];
        foreach ($prompts as $i => $prompt) {
            $lines[] = ($i + 1).'. '.$prompt;
        }

        $body = $this->normalizeBody(
            "# Домашнее задание: {$topic}\n\n".
            "Промпты для студентов (staff Accept = editorial; не auto-publish):\n\n".
            implode("\n", $lines)
        );

        return $this->enforceBodyCaps($body, $sourceText);
    }

    /**
     * @param  array<string, mixed>  $metaExtra
     */
    private function upsertKind(
        Lesson $lesson,
        string $kind,
        string $title,
        string $body,
        ?string $quote,
        array $metaExtra = [],
    ): ContentCandidate {
        $uniqueKey = 'study:'.$kind.':lesson:'.$lesson->id;
        $meta = array_merge([
            'source' => 'lecture_transcript',
            'kind' => $kind,
            'unique_key' => $uniqueKey,
            'body_chars' => mb_strlen($body),
            'generator' => empty(config('services.openrouter.api_key')) ? 'heuristic' : 'curator_ai_or_heuristic',
            'pilot_publish' => false,
        ], $metaExtra);

        $existing = ContentCandidate::query()
            ->where('type', ContentCandidate::TYPE_STUDY_ARTIFACT)
            ->where('meta->unique_key', $uniqueKey)
            ->first();

        if ($existing === null) {
            $existing = ContentCandidate::query()
                ->where('type', ContentCandidate::TYPE_STUDY_ARTIFACT)
                ->where('lesson_id', $lesson->id)
                ->where('channel_target', self::CHANNEL_STAFF_STUDY)
                ->get()
                ->first(fn (ContentCandidate $c) => ($c->meta['unique_key'] ?? null) === $uniqueKey
                    || ($c->meta['kind'] ?? null) === $kind);
        }

        if ($existing !== null && $existing->status !== ContentCandidate::STATUS_DRAFT) {
            return $existing;
        }

        $attributes = [
            'status' => ContentCandidate::STATUS_DRAFT,
            'lesson_id' => $lesson->id,
            'title' => $title,
            'body' => $body,
            'quote' => $quote,
            'channel_target' => self::CHANNEL_STAFF_STUDY,
            'meta' => $meta,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing->refresh();
        }

        return ContentCandidate::create(array_merge($attributes, [
            'type' => ContentCandidate::TYPE_STUDY_ARTIFACT,
        ]));
    }

    private function enforceBodyCaps(string $body, string $sourceText, ?int $absoluteMax = null): string
    {
        $body = $this->normalizeBody($body);
        $sourceLen = max(1, mb_strlen($sourceText));
        $ratioCap = max(1, (int) floor($sourceLen * self::MAX_BODY_RATIO));
        $cap = min($absoluteMax ?? self::MAX_BODY_CHARS, self::MAX_BODY_CHARS, $ratioCap);

        if (mb_strlen($body) <= $cap) {
            return $body;
        }

        return rtrim(mb_substr($body, 0, $cap - 1)).'…';
    }

    private function shortQuote(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        if (preg_match('/^[^.!?]+[.!?]/u', $text, $m) === 1) {
            $quote = trim($m[0]);
        } else {
            $quote = mb_substr($text, 0, 200);
        }

        try {
            QuotePolicy::assertPublicQuote($quote);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $quote;
    }

    /**
     * @param  list<array{text?:string,safe_text?:string}>  $sentences
     */
    private function joinSentenceText(array $sentences): string
    {
        $parts = [];
        foreach ($sentences as $sentence) {
            $t = trim((string) ($sentence['text'] ?? $sentence['safe_text'] ?? ''));
            if ($t !== '') {
                $parts[] = $t;
            }
        }

        return trim(implode(' ', $parts));
    }

    private function firstSentence(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        if (preg_match('/^[^.!?]+[.!?]/u', $text, $m) === 1) {
            return trim($m[0]);
        }

        return $this->capText($text, 180);
    }

    private function capText(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? $title);
        if (mb_strlen($title) > 180) {
            $title = rtrim(mb_substr($title, 0, 179)).'…';
        }

        return $title;
    }

    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = preg_replace("/\n{3,}/", "\n\n", $body) ?? $body;

        return trim($body);
    }
}
