<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\ContentCandidate;
use App\Models\Lesson;
use App\Services\Bot\CuratorAi;
use App\Services\Lecture\ClipSpanPlanner;
use App\Support\TranscriptParser;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Drafts SEO/blog/newsletter **body** articles from lecture transcripts
 * (H1550, Wave 4, ROADMAP "Wave 4", D15/D19: quotes ≤2 sentences; never a
 * full paid transcript dump). CuratorAi may polish the outline when an
 * OpenRouter key is set; a deterministic span outline covers tests and
 * no-key deploys (same idiom as FaqDraftGenerator / SocialDraftGenerator).
 *
 * Draft-only — Accept in Filament is editorial review; there is no
 * auto-publish path for type=article in this wave.
 */
final class ArticleDraftGenerator
{
    public const MAX_SECTIONS = 6;

    public const MAX_SECTION_CHARS = 420;

    public const MAX_BODY_CHARS = 3200;

    /** Body length must stay under this fraction of the full transcript text. */
    public const MAX_BODY_RATIO = 0.20;

    public const WEEKLY_LESSON_LIMIT = 5;

    public function __construct(private readonly CuratorAi $curatorAi) {}

    /**
     * One article draft per lesson (outline + sections). Idempotent on
     * (lesson_id, type=article) while status remains draft.
     */
    public function draftForLesson(Lesson $lesson): ?ContentCandidate
    {
        if (empty($lesson->transcript_file)) {
            return null;
        }

        $sentences = TranscriptParser::sentencesFromPublicFile($lesson->transcript_file);
        if ($sentences === []) {
            return null;
        }

        $sourceText = $this->joinSentenceText($sentences);
        $sections = $this->sectionsFromSpans($lesson, $sentences);
        if ($sections === []) {
            $sections = $this->fallbackSections($sentences);
        }

        $title = $this->normalizeTitle((string) ($lesson->topic ?: $lesson->title ?: 'Лекция #'.$lesson->id));
        $body = $this->composeBody($title, $sections, (string) ($lesson->topic ?: $lesson->title));
        $body = $this->enforceBodyCaps($body, $sourceText);
        $quote = $this->shortQuote($sections[0]['text'] ?? $sourceText);

        return $this->upsertArticle(
            lessonId: $lesson->id,
            title: $title,
            body: $body,
            quote: $quote,
            channelTarget: 'blog',
            meta: [
                'source' => 'lecture_transcript',
                'pack' => 'lesson',
                'lesson_ids' => [$lesson->id],
                'section_count' => count($sections),
                'source_chars' => mb_strlen($sourceText),
                'body_chars' => mb_strlen($body),
                'generator' => empty(config('services.openrouter.api_key')) ? 'heuristic' : 'curator_ai_or_heuristic',
            ],
            uniqueKey: 'lesson:'.$lesson->id,
        );
    }

    /**
     * Weekly pack: one article from recently published lessons with
     * transcripts (default last 7 days). Idempotent on the ISO week key.
     */
    public function composeWeeklyPack(?DateTimeInterface $now = null, int $days = 7): ?ContentCandidate
    {
        $now = $now === null ? now() : Carbon::parse($now);
        $since = $now->copy()->subDays(max(1, $days));

        $lessons = Lesson::query()
            ->where('is_published', true)
            ->whereRaw("COALESCE(transcript_file, '') <> ''")
            ->where(function ($q) use ($since) {
                $q->where('updated_at', '>=', $since)
                    ->orWhere('created_at', '>=', $since);
            })
            ->orderByDesc('id')
            ->limit(self::WEEKLY_LESSON_LIMIT)
            ->get();

        if ($lessons->isEmpty()) {
            return null;
        }

        $weekKey = $now->format('o-\WW');
        $allSections = [];
        $sourceChunks = [];
        $lessonIds = [];

        foreach ($lessons as $lesson) {
            $sentences = TranscriptParser::sentencesFromPublicFile($lesson->transcript_file);
            if ($sentences === []) {
                continue;
            }
            $sourceChunks[] = $this->joinSentenceText($sentences);
            $lessonIds[] = $lesson->id;
            $lessonTitle = (string) ($lesson->topic ?: $lesson->title ?: 'Лекция #'.$lesson->id);
            $spanSections = $this->sectionsFromSpans($lesson, $sentences, maxSpans: 2);
            if ($spanSections === []) {
                $spanSections = array_slice($this->fallbackSections($sentences), 0, 1);
            }
            foreach ($spanSections as $section) {
                $allSections[] = [
                    'heading' => $lessonTitle.': '.$section['heading'],
                    'text' => $section['text'],
                ];
            }
        }

        if ($allSections === [] || $lessonIds === []) {
            return null;
        }

        $allSections = array_slice($allSections, 0, self::MAX_SECTIONS);
        $sourceText = implode("\n", $sourceChunks);
        $title = $this->normalizeTitle('Дайджест лекций · неделя '.$weekKey);
        $body = $this->composeBody($title, $allSections, 'еженедельный дайджест лекций');
        $body = $this->enforceBodyCaps($body, $sourceText);
        $quote = $this->shortQuote($allSections[0]['text'] ?? '');

        return $this->upsertArticle(
            lessonId: $lessonIds[0],
            title: $title,
            body: $body,
            quote: $quote,
            channelTarget: 'newsletter_body',
            meta: [
                'source' => 'lecture_transcript',
                'pack' => 'weekly',
                'week_key' => $weekKey,
                'lesson_ids' => $lessonIds,
                'section_count' => count($allSections),
                'source_chars' => mb_strlen($sourceText),
                'body_chars' => mb_strlen($body),
                'generator' => empty(config('services.openrouter.api_key')) ? 'heuristic' : 'curator_ai_or_heuristic',
            ],
            uniqueKey: 'week:'.$weekKey,
        );
    }

    /**
     * Optional markdown export for staff (body is already markdown-ish).
     */
    public function toMarkdown(ContentCandidate $article): string
    {
        $title = trim((string) $article->title);
        $body = trim((string) $article->body);
        if ($title === '') {
            return $body;
        }
        if (str_starts_with($body, '# ')) {
            return $body;
        }

        return "# {$title}\n\n{$body}";
    }

    /**
     * @param  list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>  $sentences
     * @return list<array{heading:string,text:string}>
     */
    private function sectionsFromSpans(Lesson $lesson, array $sentences, int $maxSpans = self::MAX_SECTIONS): array
    {
        $spans = ClipSpanPlanner::planSpans($lesson, targetSeconds: 90, maxSpans: $maxSpans);
        $sections = [];

        foreach ($spans as $i => $span) {
            $start = (float) $span['start_seconds'];
            $end = (float) $span['end_seconds'];
            $chunk = [];
            foreach ($sentences as $sentence) {
                if ($sentence['start'] >= $start && $sentence['start'] < $end) {
                    $chunk[] = trim((string) $sentence['text']);
                }
            }
            $text = $this->capSection(trim(implode(' ', array_filter($chunk))));
            if ($text === '') {
                continue;
            }

            $heading = trim((string) ($span['title'] ?? ''));
            if ($heading === '') {
                $heading = 'Раздел '.($i + 1);
            }

            $sections[] = ['heading' => $heading, 'text' => $text];
        }

        return $sections;
    }

    /**
     * @param  list<array{formatted_time:string,start:float,end:float,text:string,safe_text:string}>  $sentences
     * @return list<array{heading:string,text:string}>
     */
    private function fallbackSections(array $sentences): array
    {
        $total = count($sentences);
        if ($total === 0) {
            return [];
        }

        $chunkSize = max(1, (int) ceil($total / min(self::MAX_SECTIONS, max(1, $total))));
        $sections = [];
        $n = 1;
        for ($i = 0; $i < $total && count($sections) < self::MAX_SECTIONS; $i += $chunkSize) {
            $slice = array_slice($sentences, $i, $chunkSize);
            $text = $this->capSection($this->joinSentenceText($slice));
            if ($text === '') {
                continue;
            }
            $sections[] = [
                'heading' => 'Часть '.$n,
                'text' => $text,
            ];
            $n++;
        }

        return $sections;
    }

    /**
     * @param  list<array{heading:string,text:string}>  $sections
     */
    private function composeBody(string $title, array $sections, string $topic): string
    {
        $apiKey = config('services.openrouter.api_key');
        if (! empty($apiKey)) {
            $generated = $this->generateViaCuratorAi($title, $topic, $sections);
            if ($generated !== null && trim($generated) !== '') {
                return $this->normalizeBody($generated);
            }
        }

        $parts = ["# {$title}", '', "Тема: {$topic}.", ''];
        foreach ($sections as $section) {
            $parts[] = '## '.$section['heading'];
            $parts[] = '';
            $parts[] = $section['text'];
            $parts[] = '';
        }
        $parts[] = '## Кратко';
        $parts[] = '';
        $parts[] = 'Материал подготовлен как черновик по стенограмме лекции — не полная расшифровка.';

        return $this->normalizeBody(implode("\n", $parts));
    }

    /**
     * @param  list<array{heading:string,text:string}>  $sections
     */
    private function generateViaCuratorAi(string $title, string $topic, array $sections): ?string
    {
        $outline = [];
        foreach ($sections as $section) {
            $outline[] = '- '.$section['heading'].': '.$section['text'];
        }
        $evidence = implode("\n", $outline);

        $prompt = 'Напиши черновик статьи (markdown) для блога/рассылки школы санскрита '.
            "по теме «{$topic}», заголовок «{$title}». Используй только эти фрагменты ".
            "стенограммы (не выдумывай факты, не вставляй полную лекцию):\n\n{$evidence}\n\n".
            'Структура: # заголовок, 3–6 ## разделов, 1–3 предложения на раздел. '.
            'Цитаты — максимум 1–2 предложения. Без HTML.';

        return $this->curatorAi->chat([
            ['role' => 'system', 'content' => 'Ты редактор образовательного блога. Пишешь сжатые черновики по стенограмме.'],
            ['role' => 'user', 'content' => $prompt],
        ]);
    }

    private function enforceBodyCaps(string $body, string $sourceText): string
    {
        $body = $this->normalizeBody($body);
        $sourceLen = max(1, mb_strlen($sourceText));
        // Hard dump guard: never exceed ratio of source or absolute max.
        // Do not raise a fixed floor above the ratio (that re-opens D2 leaks
        // on short transcripts).
        $ratioCap = max(1, (int) floor($sourceLen * self::MAX_BODY_RATIO));
        $cap = min(self::MAX_BODY_CHARS, $ratioCap);

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
     * @param  array<string, mixed>  $meta
     */
    private function upsertArticle(
        int $lessonId,
        string $title,
        string $body,
        ?string $quote,
        string $channelTarget,
        array $meta,
        string $uniqueKey,
    ): ContentCandidate {
        $meta['unique_key'] = $uniqueKey;

        $existing = ContentCandidate::query()
            ->where('type', ContentCandidate::TYPE_ARTICLE)
            ->where('meta->unique_key', $uniqueKey)
            ->first();

        if ($existing === null) {
            // Fallback for drivers that don't query JSON paths the same way.
            $existing = ContentCandidate::query()
                ->where('type', ContentCandidate::TYPE_ARTICLE)
                ->where('lesson_id', $lessonId)
                ->where('channel_target', $channelTarget)
                ->get()
                ->first(fn (ContentCandidate $c) => ($c->meta['unique_key'] ?? null) === $uniqueKey);
        }

        if ($existing !== null && $existing->status !== ContentCandidate::STATUS_DRAFT) {
            return $existing;
        }

        $attributes = [
            'status' => ContentCandidate::STATUS_DRAFT,
            'lesson_id' => $lessonId,
            'title' => $title,
            'body' => $body,
            'quote' => $quote,
            'channel_target' => $channelTarget,
            'meta' => $meta,
        ];

        if ($existing !== null) {
            $existing->fill($attributes)->save();

            return $existing->refresh();
        }

        return ContentCandidate::create(array_merge($attributes, [
            'type' => ContentCandidate::TYPE_ARTICLE,
        ]));
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

    private function capSection(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= self::MAX_SECTION_CHARS) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, self::MAX_SECTION_CHARS - 1)).'…';
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
