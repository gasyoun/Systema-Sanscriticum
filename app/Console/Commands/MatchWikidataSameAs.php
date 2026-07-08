<?php

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use App\Services\Seo\WikidataSameAsMatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * SEO P2 Wave-1 (H210, Track B / decision D4) — populate `dictionary_words.wikidata_qid`
 * out of band (never at request time). Re-runnable + idempotent: by default only rows
 * with a NULL qid are processed, so a re-run just fills gaps.
 *
 * D4 guardrail — SAFE BY DEFAULT: this command PROPOSES matches and writes NOTHING
 * unless `--write` is passed. Even the exact Sanskrit-script signal (1.0) is not
 * clean enough to trust unattended: a spot-check (08-07-2026, see
 * docs/WIKIDATA_SAMEAS_SPOTCHECK.md) found conceptual-disambiguation false positives
 * (dharma → a "difference between Buddhist/Jain dharma" item, not the concept). So the
 * operator runs it in proposal mode, spot-checks the table, THEN re-runs with `--write`.
 * Only matches at/above --min-score are ever written; the weaker IAST↔English signal
 * (0.75) always stays review-only under the default threshold.
 *
 * Usage:
 *   php artisan dictionary:match-wikidata --sample=30      # propose a spot-check sample
 *   php artisan dictionary:match-wikidata --write          # persist exact matches after review
 *   php artisan dictionary:match-wikidata --write --limit=500 --sleep=200
 */
class MatchWikidataSameAs extends Command
{
    protected $signature = 'dictionary:match-wikidata
        {--limit=0 : Max rows to process (0 = no limit)}
        {--sample=0 : Process a random sample of N rows (spot-check); implies a reduced scan}
        {--min-score=1.0 : Minimum confidence to WRITE a qid (D4: default = exact-only)}
        {--sleep=250 : Milliseconds to pause between API calls (politeness/rate limit)}
        {--include-set : Also re-check rows that already have a wikidata_qid}
        {--write : Actually persist qids (default: propose-only, write nothing)}';

    protected $description = 'Match headwords to Wikidata and populate wikidata_qid for the sameAs triplet (D4)';

    public function handle(WikidataSameAsMatcher $matcher): int
    {
        $minScore = (float) $this->option('min-score');
        $sleepMs = max(0, (int) $this->option('sleep'));
        $dryRun = ! $this->option('write'); // propose-only unless --write is explicit
        $limit = max(0, (int) $this->option('limit'));
        $sample = max(0, (int) $this->option('sample'));

        $query = DictionaryWord::query()
            ->whereNotNull('slug')
            ->where(function ($q) {
                $q->whereNotNull('devanagari')->orWhereNotNull('iast');
            });

        if (! $this->option('include-set')) {
            $query->whereNull('wikidata_qid');
        }

        if ($sample > 0) {
            $query->inRandomOrder()->limit($sample);
        } elseif ($limit > 0) {
            $query->limit($limit);
        }

        $words = $query->get();

        if ($words->isEmpty()) {
            $this->info('No candidate rows to match.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d row(s). Write threshold ≥ %.2f. %s',
            $dryRun ? 'Dry-run over' : 'Matching',
            $words->count(),
            $minScore,
            $dryRun ? '(no writes)' : '',
        ));

        $written = 0;
        $reviewed = 0;
        $rows = [];

        foreach ($words as $i => $word) {
            $result = $matcher->match($word);

            if ($result !== null) {
                $willWrite = $result['score'] >= $minScore && ! $dryRun;
                if ($willWrite) {
                    $word->forceFill(['wikidata_qid' => $result['qid']])->save();
                    $written++;
                } elseif ($result['score'] < $minScore) {
                    $reviewed++;
                }

                $rows[] = [
                    $word->headword(),
                    $result['qid'],
                    number_format($result['score'], 2),
                    $result['signal'],
                    $willWrite ? 'written' : ($result['score'] >= $minScore ? 'would-write' : 'review'),
                    Str::limit((string) $result['description'], 40),
                ];
            }

            if ($sleepMs > 0 && $i < $words->count() - 1) {
                usleep($sleepMs * 1000);
            }
        }

        if ($rows !== []) {
            $this->table(['headword', 'qid', 'score', 'signal', 'action', 'wd-description'], $rows);
        }

        $this->info(sprintf(
            'Done. %d match(es): %d %s, %d below-threshold (review). %d row(s) had no match.',
            count($rows),
            $written,
            $dryRun ? 'would-write' : 'written',
            $reviewed,
            $words->count() - count($rows),
        ));

        if ($dryRun) {
            $this->warn('Propose-only — nothing written (D4). Spot-check the table above, then re-run with --write.');
        }

        return self::SUCCESS;
    }
}
