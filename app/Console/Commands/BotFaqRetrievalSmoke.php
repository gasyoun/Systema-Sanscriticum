<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Bot\BotKnowledgeBase;
use App\Services\Support\Faq\FaqCorpusParser;
use Illuminate\Console\Command;

/**
 * H3766 B4 — смоук стадии 1: старая и новая база знаний бота бок о бок.
 *
 * На каждый вопрос печатается, сколько символов FAQ ушло бы в системный промпт
 * ДО ретривала (весь корпус) и ПОСЛЕ (top-K разделов), и — главное — остался ли
 * в этих top-K раздел, размеченный в eval-наборе как правильный. Экономия
 * токенов без этой колонки ничего не значит: обрезать промпт до нуля дешевле
 * всего.
 *
 * Команда НИЧЕГО не отправляет и не трогает флаг: она читает committed-фикстуру
 * и корпус.
 */
class BotFaqRetrievalSmoke extends Command
{
    protected $signature = 'bot:faq-retrieval-smoke
        {--limit=24 : сколько вопросов взять}
        {--fixture=tests/fixtures/faq_rag_eval.json : откуда брать вопросы}
        {--real-only : только вопросы из telegram_support_messages, без рукописных}';

    protected $description = 'H3766 B4: side-by-side smoke of the bot knowledge base with and without bot_faq_retrieval';

    public function handle(BotKnowledgeBase $kb): int
    {
        $path = base_path((string) $this->option('fixture'));
        if (! is_file($path)) {
            $this->error("no fixture at {$path}");

            return self::FAILURE;
        }

        /** @var array{items: list<array<string, mixed>>} $fixture */
        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $items = $fixture['items'];

        if ($this->option('real-only')) {
            $items = array_values(array_filter(
                $items,
                static fn (array $i): bool => str_starts_with((string) ($i['source'] ?? ''), 'telegram_support_messages'),
            ));
        }

        $limit = max(1, (int) $this->option('limit'));
        $step = max(1, intdiv(count($items), $limit));
        $sample = [];
        for ($i = 0; $i < count($items) && count($sample) < $limit; $i += $step) {
            $sample[] = $items[$i];
        }

        config(['features.bot_faq_retrieval' => false]);
        $whole = mb_strlen($kb->faqFor('прогрев кеша'));

        $this->line('| # | Кат. | Вопрос | FAQ в промпте, симв. | Разделы в top-K | Нужный раздел попал |');
        $this->line('|---|---|---|---|---|---|');

        $kept = 0;
        $narrowedTotal = 0;
        $row = 0;

        foreach ($sample as $item) {
            $row++;
            $question = (string) $item['question'];
            $expected = (array) $item['expected_chunk_ids'];

            config(['features.bot_faq_retrieval' => true]);
            $narrowed = $kb->faqFor($question);
            $narrowedTotal += mb_strlen($narrowed);

            $hitTitles = [];
            $hit = false;
            foreach ($expected as $chunkId) {
                $title = $this->titleOf((string) $chunkId);
                $hitTitles[] = $title;
                if ($title !== '' && str_contains($narrowed, $title)) {
                    $hit = true;
                }
            }
            if ($hit) {
                $kept++;
            }

            $this->line(sprintf(
                '| %d | %s | %s | %d → **%d** | %d | %s |',
                $row,
                (string) ($item['category'] ?? '-'),
                str_replace('|', '\\|', mb_substr($question, 0, 90)),
                $whole,
                mb_strlen($narrowed),
                (int) config('support.faq_rag.bot_top_k', 6),
                $hit ? '✅' : '❌ '.implode(', ', $hitTitles),
            ));
        }

        $n = max(1, count($sample));
        $this->line('');
        $this->line(sprintf(
            '**Итог:** %d/%d — нужный раздел остался в промпте (%.0f %%). Средний размер FAQ-половины промпта %d → %d символов (−%.0f %%).',
            $kept,
            $n,
            $kept / $n * 100,
            $whole,
            (int) round($narrowedTotal / $n),
            $whole > 0 ? (1 - ($narrowedTotal / $n) / $whole) * 100 : 0,
        ));

        config(['features.bot_faq_retrieval' => false]);

        return self::SUCCESS;
    }

    /** Заголовок раздела по chunk_id — по нему и проверяем присутствие в промпте. */
    private function titleOf(string $chunkId): string
    {
        $parts = explode('/', $chunkId);
        $slug = (string) end($parts);

        foreach (app(FaqCorpusParser::class)->chunks() as $chunk) {
            if (str_ends_with($chunk->chunkId, '/'.$slug) || $chunk->chunkId === $chunkId) {
                return $chunk->title;
            }
        }

        return '';
    }
}
