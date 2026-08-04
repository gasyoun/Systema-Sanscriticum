<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Support\StartChteniyaCohort;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * H2106 — «Старт чтения» cohort SRS import.
 *
 * Reads the vendored lemma feed resources/data/cohort_start_chteniya/lemmas_for_srs.tsv
 * (H2109 freeze) and gives each currently-entitled cohort student their own PRIVATE
 * SrsDeck of that pack's lemmas — one deck per user, never a shared `system`/`public`
 * deck. That is the entitlement gate for this command: a `system`-visibility deck
 * would show in every logged-in user's /dvaram/koloda hub regardless of payment
 * (SrsController::cabinetIndex has no per-deck entitlement check, only the global
 * srs.enabled flag), so gating must happen at deck OWNERSHIP instead — a `private`
 * deck with `user_id` set is only ever returned by that same query's
 * `orWhere('user_id', $userId)` branch.
 *
 * Gated by config('features.start_chteniya_cohort') — the SAME cohort-scoped flag
 * H2105/H2110/H2111 already gate on, never config('srs.enabled') or the global
 * SRS flag (H2106's own fence: no global SRS_ENABLED=true).
 *
 *   php artisan srs:import-start-chteniya-cohort
 *   php artisan srs:import-start-chteniya-cohort --dry-run
 */
class ImportStartChteniyaCohortSrsDeck extends Command
{
    protected $signature = 'srs:import-start-chteniya-cohort
                            {--dry-run : Report counts only, write nothing}
                            {--path= : Override the feed path (tests only; defaults to the vendored resource file)}';

    protected $description = 'Import the H2109 cohort lemma freeze into a private per-student SRS deck (H2106)';

    private const FEED_PATH = 'data/cohort_start_chteniya/lemmas_for_srs.tsv';

    private const DECK_SLUG = 'start-chteniya-cohort';

    public function handle(): int
    {
        if (! config('features.start_chteniya_cohort', false)) {
            $this->warn('features.start_chteniya_cohort is OFF — nothing imported.');

            return self::SUCCESS;
        }

        try {
            $rows = $this->readFeed();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $users = StartChteniyaCohort::entitledUsers();

        $this->info(count($rows).' lemma row(s) in feed across '.
            count(array_unique(array_column($rows, 'pack'))).' pack(s); '.
            $users->count().' entitled user(s).');

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->line('[dry-run] no writes will be made');
            $this->info('[dry-run] would create/update 1 private deck per entitled user, '.
                count($rows).' card(s) each.');

            return self::SUCCESS;
        }

        if ($users->isEmpty()) {
            $this->info('No entitled users yet — nothing to import.');

            return self::SUCCESS;
        }

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'start_chteniya_cohort'],
            [
                'name' => '«Старт чтения» — cohort lemmas',
                'language' => 'sa',
                'fields' => ['pack', 'lemma_slp1', 'surface', 'gloss_ru', 'gloss_en', 'locus'],
            ],
        );

        $decksTouched = 0;
        $cardsImported = 0;
        $cardsExisting = 0;

        foreach ($users as $user) {
            $deck = SrsDeck::firstOrCreate(
                ['user_id' => $user->id, 'slug' => self::DECK_SLUG],
                [
                    'note_type_id' => $noteType->id,
                    'name' => '«Старт чтения» — словарь когорты',
                    'language' => 'sa',
                    'visibility' => 'private',
                    'description' => 'H2106: lemmas from the cohort reading packs (Hitopadeśa-0), pinned by the H2109 freeze.',
                ],
            );
            $decksTouched++;

            $existingLemmas = SrsCard::query()
                ->where('deck_id', $deck->id)
                ->get(['fields'])
                ->map(fn (SrsCard $card) => ($card->fields['pack'] ?? null).'|'.($card->fields['lemma_slp1'] ?? null))
                ->flip();

            foreach ($rows as $row) {
                $key = $row['pack'].'|'.$row['lemma_slp1'];
                if ($existingLemmas->has($key)) {
                    $cardsExisting++;

                    continue;
                }

                SrsCard::create([
                    'deck_id' => $deck->id,
                    'direction' => 'front_back',
                    'source_word_id' => null,
                    'fields' => [
                        'pack' => $row['pack'],
                        'lemma_slp1' => $row['lemma_slp1'],
                        'surface' => $row['surface'],
                        'gloss_ru' => $row['gloss_ru'],
                        'gloss_en' => $row['gloss_en'],
                        'locus' => $row['locus'],
                    ],
                ]);
                $cardsImported++;
            }
        }

        $this->newLine();
        $this->info("Touched {$decksTouched} deck(s), imported {$cardsImported} new card(s), {$cardsExisting} already present.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{pack:string,lemma_slp1:string,surface:string,gloss_ru:string,gloss_en:string,locus:string}>
     */
    private function readFeed(): array
    {
        $path = (string) ($this->option('path') ?: resource_path(self::FEED_PATH));

        if (! is_file($path)) {
            throw new RuntimeException("Feed not found at {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $lines = preg_split('/\r\n|\n|\r/', trim($raw));
        if ($lines === false || count($lines) < 2) {
            throw new RuntimeException("Empty or malformed feed at {$path}");
        }

        $header = str_getcsv(array_shift($lines), "\t");
        $rows = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cells = str_getcsv($line, "\t");
            if (count($cells) !== count($header)) {
                throw new RuntimeException("Column count mismatch in {$path}: ".$line);
            }
            $rows[] = array_combine($header, $cells);
        }

        return $rows;
    }
}
