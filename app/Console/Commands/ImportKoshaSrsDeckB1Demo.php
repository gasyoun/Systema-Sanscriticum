<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Database\Seeders\SrsSanskritDeckSeeder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * H955 — Rung B1 of the kosha->Systema last-mile pipeline
 * (SanskritGrammar docs/LAST_MILE_PIPELINE_SPEC.md, Hop B).
 *
 * Reads the VENDORED static feed resources/data/kosha_srs_deck_b1_demo.json
 * (kosha manifest id kosha-srs-deck-b1-demo — our own derived data, no live
 * dependency on kosha) and creates one SYSTEM SrsDeck + SrsCards, mirroring
 * {@see ImportMemriseSrsDeck} / {@see SrsSanskritDeckSeeder}:
 * Dictionary / SrsNoteType / SrsDeck / DictionaryWord / SrsCard are all
 * firstOrCreate'd keyed on stable identity, so a re-run never duplicates.
 *
 * Card ORDER: srs_cards has no sort_rank/order column (spec's noted gap —
 * a schema migration is deliberately deferred to a human-reviewed follow-up),
 * so this command's only order signal is INSERTION order — it inserts the
 * feed's cards in ascending `rank` (the feed is already core_rank-sorted), one
 * `firstOrCreate` call at a time, so a first run's card `id` order == `rank`
 * order.
 *
 * Gated by config('features.kosha_srs'); OFF by default (mirrors
 * slovar_enrichment) — with the flag off this command writes nothing.
 *
 *   php artisan srs:import-kosha-b1-demo
 *   php artisan srs:import-kosha-b1-demo --dry-run
 */
class ImportKoshaSrsDeckB1Demo extends Command
{
    protected $signature = 'srs:import-kosha-b1-demo
                            {--dry-run : Report counts only, write nothing}
                            {--path= : Override the feed path (tests only; defaults to the vendored resource file)}';

    protected $description = 'Import the kosha Rung-B1 demo SRS deck (H955) into the SRS engine';

    private const FEED_PATH = 'data/kosha_srs_deck_b1_demo.json';

    private const DECK_SLUG = 'kosha-b1-demo';

    public function handle(): int
    {
        if (! config('features.kosha_srs', false)) {
            $this->warn('features.kosha_srs is OFF — nothing imported. Set KOSHA_SRS=true to enable.');

            return self::SUCCESS;
        }

        try {
            $feed = $this->readFeed();
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cards = $feed['cards'];

        $this->info('kosha-srs-deck-b1-demo — '.count($cards).' card(s) in feed');
        $this->line($dryRun ? '[dry-run] no writes will be made' : 'importing...');

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry-run] would import 1 deck, '.count($cards).' card(s).');

            return self::SUCCESS;
        }

        $dictionary = Dictionary::firstOrCreate(
            ['name' => 'kosha Rung B1 demo'],
            ['description' => 'H955 last-mile pipeline demo — vendored from kosha kosha-srs-deck-b1-demo.', 'is_active' => true],
        );

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'kosha_b1_demo'],
            [
                'name' => 'kosha — Rung B1 demo',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation', 'slp1', 'rank'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => self::DECK_SLUG, 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'kosha — Rung B1 demo (Nala 1 vocabulary)',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'H955: content vocabulary of the Nala-1 reading pack, core_rank-ordered, function words stripped.',
            ],
        );

        $imported = 0;
        $existing = 0;

        // Feed is already sorted by `rank` ascending — inserting in feed order
        // keeps card `id` order == `rank` order on a first run.
        foreach ($cards as $card) {
            $word = DictionaryWord::firstOrCreate(
                ['dictionary_id' => $dictionary->id, 'iast' => $card['iast']],
                [
                    'devanagari' => $card['deva'],
                    'translation' => $card['gloss'],
                    'slug' => 'kosha-b1-'.$card['slp1'],
                ],
            );

            $srsCard = SrsCard::firstOrCreate(
                ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                [
                    'direction' => 'front_back',
                    'fields' => [
                        'devanagari' => $card['deva'],
                        'iast' => $card['iast'],
                        'translation' => $card['gloss'],
                        'slp1' => $card['slp1'],
                        'rank' => $card['rank'],
                    ],
                ],
            );

            $srsCard->wasRecentlyCreated ? $imported++ : $existing++;
        }

        $this->newLine();
        $this->info("Imported 1 deck, {$imported} new card(s), {$existing} already present.");

        return self::SUCCESS;
    }

    /**
     * @return array{id:string,cards:list<array{rank:int,slp1:string,deva:string,iast:string,gloss:string}>}
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

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['cards']) || ! is_array($data['cards'])) {
            throw new RuntimeException("Invalid or malformed feed at {$path}");
        }

        return $data;
    }
}
