<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SupportAnswerSuggestion;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\Faq\HybridRetriever;
use App\Services\Support\SupportQuestionCategorizer;
use Illuminate\Console\Command;

/**
 * 0YW: one smoke over real mined questions for the F lane that now exists on
 * both the webchat and the cabinet bot. Read-only: it prints the decision the
 * shared gates would take (category F + BM25 ≥ F floor), never sends anything.
 *
 * End-to-end sends stay in tests: SupportWebchatAutoReplyTest (webchat) and
 * CuratorAiFaqCitationTest (cabinet). This command is the 20-question evidence
 * that the same floor and the same hard {F} allowlist hold on real text.
 */
class SupportFLaneSmoke extends Command
{
    protected $signature = 'support:f-lane-smoke
        {--limit=20 : how many real questions to sample}
        {--fixture=tests/fixtures/faq_rag_eval.json : eval set to sample from}';

    protected $description = '0YW: 20-question read-only smoke of the shared F-lane decision (webchat + cabinet bot)';

    public function handle(Bm25FaqRetriever $retriever): int
    {
        $path = base_path((string) $this->option('fixture'));
        if (! is_file($path)) {
            $this->error("no fixture at {$path}");

            return self::FAILURE;
        }

        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $items = array_values(array_filter(
            $fixture['items'] ?? [],
            static fn (array $i): bool => empty($i['must_refuse'])
                && str_starts_with((string) ($i['source'] ?? ''), 'telegram_support_messages'),
        ));

        if ($items === []) {
            $this->error('no real mined questions in the fixture');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $floor = (float) config('support.faq_rag.shadow_min_score_by_category.'.SupportAnswerSuggestion::CATEGORY_MATERIALS, 15.7);

        // A smoke with zero positives proves nothing. Sample the F questions
        // first (the lane under test), then fill the rest with non-F negatives.
        $fItems = [];
        $otherItems = [];
        foreach ($items as $item) {
            if (SupportQuestionCategorizer::categorize((string) $item['question']) === SupportAnswerSuggestion::CATEGORY_MATERIALS) {
                $fItems[] = $item;
            } else {
                $otherItems[] = $item;
            }
        }
        $takeF = min(count($fItems), $limit);
        $negatives = min(max(0, $limit - $takeF), count($otherItems));
        $sample = array_merge(
            array_slice($fItems, 0, $takeF),
            array_slice($otherItems, 0, $negatives),
        );

        $this->line(sprintf('F floor=%.1f; categories hard-allowlisted to {F}; sample=%d of %d real questions (%d classified F, %d negatives)', $floor, count($sample), count($items), count($fItems), $negatives));
        $this->line('| # | id | cat | BM25 | decision | cited section |');
        $this->line('|---|---|---|---|---|---|');

        $send = 0;
        $notF = 0;
        $below = 0;
        $n = 0;

        foreach ($sample as $item) {
            $n++;
            $question = (string) $item['question'];
            $category = SupportQuestionCategorizer::categorize($question);
            $hits = $retriever->retrieve($question, 1);
            $score = $hits === [] ? 0.0 : HybridRetriever::bm25Score($hits[0]);
            $title = $hits === [] ? '' : trim((string) ($hits[0]['title'] ?? ''));

            if ($category !== SupportAnswerSuggestion::CATEGORY_MATERIALS) {
                $notF++;
                $decision = 'refuse: not F ('.($category ?? '—').')';
            } elseif ($score < $floor) {
                $below++;
                $decision = 'refuse: F below floor';
            } else {
                $send++;
                $decision = 'would send (webchat + cabinet)';
            }

            $this->line(sprintf(
                '| %d | %s | %s | %.1f | %s | %s |',
                $n,
                (string) $item['id'],
                $category ?? '—',
                $score,
                $decision,
                $title === '' ? '—' : $title,
            ));
        }

        $this->line('');
        $this->line(sprintf(
            'smoke: %d questions — would send %d, refused by category %d, refused below floor %d',
            $n,
            $send,
            $notF,
            $below,
        ));

        return self::SUCCESS;
    }
}
