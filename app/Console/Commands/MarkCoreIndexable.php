<?php

namespace App\Console\Commands;

use App\Models\DictionaryWord;
use Illuminate\Console\Command;

/**
 * SEO P2 Wave-1 (H210, Track A / decision D1) — wire the curated-core allowlist.
 *
 * Feeds `dictionary_words.is_indexable` from a headword list (the «Сборное ядро»
 * ~7.5k / DCS-attested core that lives in the SanskritLexicography repos). Each list
 * line is reduced to the SAME headword slug the URLs use (DictionaryWord::makeHeadwordSlug,
 * decision D3), so «ātman», «atman», «आत्मन्» all resolve to the one canonical row set.
 *
 * Idempotent + re-runnable: marks the intersection true; with --reset it first clears
 * every flag so the allowlist is authoritative (a headword dropped from the list is
 * demoted back to noindex on the next run).
 *
 * The shipped list is database/data/seo/seo_core_headwords_dcs_lexical_cores.txt (7,120
 * IAST headwords). Its ORDER is the D2 promotion order — tier 2 pril10 ultra-core first,
 * then tier 3 pril5 by period spread, then the tier 4 sbornoe remainder — so a wave is a
 * prefix of the file and --limit=N is all a wave needs.
 *
 * Usage:
 *   php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --dry-run
 *   php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --limit=435   # wave 1 = tier 2
 *   php artisan dictionary:mark-core-indexable database/data/seo/seo_core_headwords_dcs_lexical_cores.txt --limit=2000  # wave 2 (idempotent, adds the delta)
 *   php artisan dictionary:mark-core-indexable core.txt --reset --dry-run
 *
 * List format: one headword per line (IAST / Cyrillic / Devanagari). Blank lines and
 * lines beginning with '#' or ';' are ignored; a CSV line uses its first column.
 */
class MarkCoreIndexable extends Command
{
    protected $signature = 'dictionary:mark-core-indexable
        {file : Path to the curated-core headword list}
        {--reset : Clear every is_indexable flag before applying the list (list becomes authoritative)}
        {--limit= : Promote only the first N headwords of the list — one D2 wave}
        {--dry-run : Report the counts without writing}';

    protected $description = 'Flag the curated-core headwords (D1) as index-eligible from a headword list file';

    public function handle(): int
    {
        $file = $this->argument('file');

        if (! is_file($file) || ! is_readable($file)) {
            $this->error("List file not found or unreadable: {$file}");

            return self::FAILURE;
        }

        $slugs = $this->readSlugs($file);

        if ($slugs === []) {
            $this->error('No usable headwords parsed from the list.');

            return self::FAILURE;
        }

        // D2 — promote in monitored waves, not all at once. The list file's own order is
        // the promotion order (strongest lexical core first), so a wave is a prefix of it.
        $limit = $this->option('limit');
        if ($limit !== null && $limit !== '') {
            if (! ctype_digit((string) $limit) || (int) $limit < 1) {
                $this->error('--limit must be a positive integer.');

                return self::FAILURE;
            }

            $parsed = count($slugs);
            $slugs = array_slice($slugs, 0, (int) $limit);
            $this->line(sprintf('Wave: taking the first %d of %d list headword(s).', count($slugs), $parsed));
        }

        // How many DB rows the list actually resolves to (distinct slugs present).
        $matchingRows = DictionaryWord::query()->whereIn('slug', $slugs)->count();
        $matchedSlugs = DictionaryWord::query()->whereIn('slug', $slugs)->distinct()->count('slug');

        $this->info(sprintf(
            'Parsed %d unique headword slug(s); %d resolve to the dictionary (%d row(s)).',
            count($slugs),
            $matchedSlugs,
            $matchingRows,
        ));

        if ($this->option('dry-run')) {
            $currentlyOn = DictionaryWord::query()->where('is_indexable', true)->count();
            $this->line("Dry run — no writes. Currently flagged index-eligible: {$currentlyOn} row(s).");

            return self::SUCCESS;
        }

        if ($this->option('reset')) {
            $cleared = DictionaryWord::query()->where('is_indexable', true)->update(['is_indexable' => false]);
            $this->line("Reset: cleared {$cleared} previously-flagged row(s).");
        }

        $updated = DictionaryWord::query()
            ->whereIn('slug', $slugs)
            ->where('is_indexable', false)
            ->update(['is_indexable' => true]);

        $total = DictionaryWord::query()->where('is_indexable', true)->count();
        $this->info("Marked {$updated} newly index-eligible row(s). Total curated-core rows now: {$total}.");

        return self::SUCCESS;
    }

    /**
     * Parse the list into the set of canonical headword slugs. Reuses the model's
     * slug logic so matching is identical to how /slovar/{slug} keys pages.
     *
     * @return array<int, string>
     */
    private function readSlugs(string $file): array
    {
        $slugs = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            // Take the first CSV/TSV column if the list is tabular.
            $headword = trim(preg_split('/[\t,]/', $line)[0] ?? '');
            if ($headword === '') {
                continue;
            }

            // The list may carry any script; treat the token as iast first, then let
            // the model fall through to cyrillic/devanagari handling.
            $slug = DictionaryWord::makeHeadwordSlug($headword, $headword, $headword);
            if ($slug !== '') {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }
}
