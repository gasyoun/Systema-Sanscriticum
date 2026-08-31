<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportAnswerSuggestion;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * H3766 B1 — собрать КАНДИДАТОВ для tests/fixtures/faq_rag_eval.json из реальных
 * входящих вопросов поддержки.
 *
 * Команда НИКОГДА не пишет сам фикстур: она отдаёт анонимизированных кандидатов
 * с предложенным BM25 top-10, а разметку expected_chunk_ids делает человек/агент
 * поштучно (план R6: «ambiguous items dropped, never guessed»).
 *
 * ПДн: наружу уходит только текст вопроса, прогнанный через anonymise() — хэндлы,
 * e-mail, телефоны, ссылки, длинные цифровые ID и обращения по имени заменяются
 * плейсхолдерами. Ни telegram_user_id, ни имени контакта, ни chat_id в выводе нет
 * by construction: SELECT их не читает.
 *
 * Два входа:
 *   --from-tsv=<path>  офлайн-дамп `id<TAB>date<TAB>text` (разработка без прод-БД)
 *   (по умолчанию)     telegram_support_messages на текущем соединении
 */
class FaqEvalSetBuild extends Command
{
    protected $signature = 'faq:eval-set-build
        {--since=2025-01-01 : нижняя граница sent_at}
        {--per-category=25 : сколько кандидатов на категорию A–F}
        {--top-k=10 : сколько BM25-кандидатов показывать на вопрос}
        {--from-tsv= : офлайн-дамп id<TAB>date<TAB>text вместо запроса к БД}
        {--out=storage/app/faq_eval_candidates.json : куда писать кандидатов}';

    protected $description = 'H3766 B1: mine anonymised real support questions + BM25 candidates for the FAQ RAG eval set';

    /** Минимальная/максимальная длина осмысленного вопроса. */
    private const MIN_LEN = 15;

    private const MAX_LEN = 400;

    public function handle(SupportAnswerSuggester $suggester, Bm25FaqRetriever $retriever): int
    {
        $rows = $this->option('from-tsv')
            ? $this->readTsv((string) $this->option('from-tsv'))
            : $this->readDb((string) $this->option('since'));

        $this->info(sprintf('inbound rows: %d', count($rows)));

        $perCategory = max(1, (int) $this->option('per-category'));
        $topK = max(1, (int) $this->option('top-k'));

        $seen = [];
        $buckets = [];
        $stats = ['read' => count($rows), 'not_question' => 0, 'duplicate' => 0, 'uncategorised' => 0, 'kept' => 0];

        foreach ($rows as $row) {
            $text = $this->anonymise((string) $row['text']);
            if (! $this->looksLikeQuestion($text)) {
                $stats['not_question']++;

                continue;
            }

            $key = $this->dedupeKey($text);
            if (isset($seen[$key])) {
                $stats['duplicate']++;

                continue;
            }
            $seen[$key] = true;

            $category = $suggester->categorize($text);
            if ($category === null) {
                $stats['uncategorised']++;

                continue;
            }

            $buckets[$category] ??= [];
            if (count($buckets[$category]) >= $perCategory) {
                continue;
            }

            $hits = $retriever->retrieve($text, $topK);
            $buckets[$category][] = [
                'source_id' => (int) $row['id'],
                'date' => (string) $row['date'],
                'category' => $category,
                'question' => $text,
                'bm25_candidates' => array_map(static fn (array $h): array => [
                    'chunk_id' => $h['chunk_id'],
                    'title' => $h['title'],
                    'score' => $h['score'],
                ], $hits),
                'expected_chunk_ids' => [],
            ];
            $stats['kept']++;
        }

        ksort($buckets);
        $items = [];
        foreach ($buckets as $category => $bucket) {
            foreach ($bucket as $item) {
                $items[] = $item;
            }
            $this->line(sprintf('  %s: %d', $category, count($bucket)));
        }

        $payload = [
            'generated_by' => 'php artisan faq:eval-set-build',
            'handoff' => 'H3766',
            'note' => 'CANDIDATES ONLY — expected_chunk_ids must be hand-checked before they reach tests/fixtures/faq_rag_eval.json',
            'stats' => $stats,
            'categories' => array_keys($buckets),
            'items' => $items,
        ];

        $out = (string) $this->option('out');
        $path = (str_starts_with($out, '/') || preg_match('/^[A-Za-z]:/', $out) === 1)
            ? $out
            : base_path($out);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info(sprintf('wrote %d candidates -> %s', count($items), $path));
        $this->line((string) json_encode($stats, JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    /** @return list<array{id: int, date: string, text: string}> */
    private function readDb(string $since): array
    {
        return DB::table('telegram_support_messages')
            ->where('direction', 'incoming')
            ->whereNotNull('text')
            ->where('sent_at', '>=', $since)
            ->orderByDesc('sent_at')
            ->get(['id', 'sent_at', 'text'])
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'date' => substr((string) $r->sent_at, 0, 10),
                'text' => (string) $r->text,
            ])
            ->all();
    }

    /** @return list<array{id: int, date: string, text: string}> */
    private function readTsv(string $path): array
    {
        if (! is_file($path)) {
            $this->error("no such dump: {$path}");

            return [];
        }

        $out = [];
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        while (($line = fgets($fh)) !== false) {
            $parts = explode("\t", rtrim($line, "\r\n"), 3);
            if (count($parts) < 3) {
                continue;
            }
            $out[] = ['id' => (int) $parts[0], 'date' => $parts[1], 'text' => $parts[2]];
        }
        fclose($fh);

        return $out;
    }

    /**
     * Убрать ПДн. Порядок важен: сначала длинные структуры (email, телефон, ссылка),
     * потом хэндлы, потом обращения по имени.
     */
    public function anonymise(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.]+/u', '[email]', $text) ?? $text;
        $text = preg_replace('/https?:\/\/\S+/u', '[link]', $text) ?? $text;
        $text = preg_replace('/(?<![\w.])(?:\+?\d[\d\s().-]{8,}\d)(?![\w.])/u', '[phone]', $text) ?? $text;
        $text = preg_replace('/(?<![\w\/])@[A-Za-z][A-Za-z0-9_]{3,}/u', '[handle]', $text) ?? $text;
        $text = preg_replace('/^(\p{Lu}\p{Ll}+),\s+(?=(?:добр|здравств|привет|скажит|подскаж))/u', '[name], ', $text) ?? $text;
        $text = preg_replace('/\b(здравствуйте|добрый день|добрый вечер|доброе утро|привет),\s+\p{Lu}\p{Ll}+\b/iu', '$1, [name]', $text) ?? $text;
        $text = preg_replace('/(?<!\d)\d{7,}(?!\d)/u', '[id]', $text) ?? $text;

        return trim($text);
    }

    /** Вопрос ли это по форме — «?» или вопросительный зачин. */
    public function looksLikeQuestion(string $text): bool
    {
        $len = mb_strlen($text);
        if ($len < self::MIN_LEN || $len > self::MAX_LEN) {
            return false;
        }
        if (str_contains($text, '?')) {
            return true;
        }

        return preg_match(
            '/(подскажите|скажите|не могу|как |где |когда |сколько|почему|можно ли|нужно ли|какой|какая|какие|что делать|помогите)/iu',
            $text,
        ) === 1;
    }

    /** Ключ дедупликации: только буквы/цифры в нижнем регистре. */
    public function dedupeKey(string $text): string
    {
        $k = mb_strtolower($text, 'UTF-8');
        $k = preg_replace('/[^\p{L}\p{N}]+/u', '', $k) ?? $k;

        return mb_substr($k, 0, 120);
    }

    /** @return list<string> A–F */
    public static function categories(): array
    {
        return [
            SupportAnswerSuggestion::CATEGORY_ZOOM,
            SupportAnswerSuggestion::CATEGORY_RECORDING,
            SupportAnswerSuggestion::CATEGORY_SCHEDULE,
            SupportAnswerSuggestion::CATEGORY_PAYMENT,
            SupportAnswerSuggestion::CATEGORY_ACCESS,
            SupportAnswerSuggestion::CATEGORY_MATERIALS,
        ];
    }
}
