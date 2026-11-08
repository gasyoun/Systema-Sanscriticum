<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Support\Faq\Bm25FaqRetriever;
use App\Services\Support\SupportAnswerFactResolver;
use App\Services\Support\SupportAnswerSuggester;
use Illuminate\Console\Command;

/**
 * 0YZ (18-10-2026): who answers each of the 100 eval questions if the live
 * pipeline sees it — the fact resolver (personal LMS facts), the prose/FAQ
 * retriever, both, or nobody.
 *
 * Read-only by construction: with no --user it probes an empty user (id 0) and
 * every resolver returns null; with --user=N it reads that student's LMS rows
 * and writes nothing. The point is to expose fact-lane hijack: a general policy
 * question whose category makes the resolver answer with a personal fact before
 * the prose lane ever runs.
 */
class KnowledgeFactCoverage extends Command
{
    protected $signature = 'knowledge:fact-coverage
        {--evalset=tests/fixtures/faq_rag_eval.json : golden set with question/category/expected ids}
        {--user= : student id for fact resolution (default: empty probe user, facts all null)}
        {--top-k=5 : prose lane = expected chunk found in top-K}';

    protected $description = '0YZ: fact-resolver vs prose coverage map over the eval set (read-only)';

    public function handle(
        SupportAnswerSuggester $suggester,
        SupportAnswerFactResolver $resolver,
        Bm25FaqRetriever $retriever,
    ): int {
        $path = base_path((string) $this->option('evalset'));
        if (! is_file($path)) {
            $this->error("no eval set: {$path}");

            return self::FAILURE;
        }

        $fixture = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $items = $fixture['items'] ?? [];
        if (! is_array($items) || $items === []) {
            $this->error('eval set has no items');

            return self::FAILURE;
        }

        $userId = $this->option('user');
        if (is_string($userId) && $userId !== '') {
            $user = User::query()->find((int) $userId);
            if ($user === null) {
                $this->error("no user #{$userId}");

                return self::FAILURE;
            }
        } else {
            $user = new User;
            $user->id = 0;
        }

        $topK = max(1, (int) $this->option('top-k'));
        $liveTypes = array_map('strval', (array) config('support.facts.live_types', []));
        $counts = ['fact' => 0, 'both' => 0, 'prose' => 0, 'nobody' => 0];
        $drafts = 0;
        $byLane = [];
        $factIds = [];
        $hijackIds = [];
        $skipped = 0;

        foreach ($items as $item) {
            if (! empty($item['must_refuse'])) {
                $skipped++;

                continue;
            }

            $question = (string) $item['question'];
            $expected = array_values(array_map('strval', $item['expected_chunk_ids'] ?? []));
            $category = $suggester->categorize($question);

            $fact = null;
            if ($category !== null) {
                try {
                    $fact = $resolver->resolve($category, $user, $question);
                } catch (\Throwable) {
                    $fact = null;
                }
            }

            $hits = $retriever->retrieve($question, $topK);
            $retrieved = array_map(static fn (array $h): string => (string) $h['chunk_id'], $hits);
            $prose = $expected !== [] && array_intersect($expected, $retrieved) !== [];

            // A fact "answers" only when the live lane would auto-send it:
            // policy auto AND type in support.facts.live_types AND not in the
            // code-level NEVER_AUTO_TYPES fence. Draft-only facts are counted
            // separately — they reach the curator, not the student.
            $type = (string) ($fact['facts']['type'] ?? '');
            $policy = (string) ($fact['send_policy'] ?? '');
            $factLive = $fact !== null
                && $policy === SupportAnswerFactResolver::POLICY_AUTO
                && in_array($type, $liveTypes, true)
                && ! in_array($type, SupportAnswerFactResolver::NEVER_AUTO_TYPES, true);

            if ($fact !== null) {
                $drafts++;
            }

            $lane = $factLive && $prose ? 'both' : ($factLive ? 'fact' : ($prose ? 'prose' : 'nobody'));
            $counts[$lane]++;
            $byLane[$category ?? '-'][$lane] = ($byLane[$category ?? '-'][$lane] ?? 0) + 1;

            if ($factLive) {
                $factIds[] = (string) $item['id'];
                if ($prose) {
                    $hijackIds[] = (string) $item['id'];
                }
            }
        }

        $total = array_sum($counts);
        $this->line(sprintf(
            'eval items=%d, refusal cards skipped=%d, probe user=%s, top_k=%d',
            count($items),
            $skipped,
            $user->id > 0 ? '#'.$user->id : 'empty (id 0)',
            $topK,
        ));
        $this->line(sprintf(
            'fact drafts (any policy, curator-facing): %d; live fact types: %s',
            $drafts,
            $liveTypes === [] ? '(none)' : implode(',', $liveTypes),
        ));
        $this->line('');
        $this->line('| lane | n | % |');
        $this->line('|---|---|---|');
        foreach ($counts as $lane => $n) {
            $this->line(sprintf('| %s | %d | %.1f%% |', $lane, $n, $total > 0 ? $n / $total * 100 : 0));
        }

        $this->line('');
        $this->line('| category | fact | both | prose | nobody |');
        $this->line('|---|---|---|---|---|');
        ksort($byLane);
        foreach ($byLane as $category => $lanes) {
            $this->line(sprintf(
                '| %s | %d | %d | %d | %d |',
                $category,
                $lanes['fact'] ?? 0,
                $lanes['both'] ?? 0,
                $lanes['prose'] ?? 0,
                $lanes['nobody'] ?? 0,
            ));
        }

        if ($factIds !== []) {
            $this->line('');
            $this->line('fact-lane ids: '.implode(', ', $factIds));
        }
        if ($hijackIds !== []) {
            $this->line('fact+prose overlap (hijack risk): '.implode(', ', $hijackIds));
        }

        return self::SUCCESS;
    }
}
