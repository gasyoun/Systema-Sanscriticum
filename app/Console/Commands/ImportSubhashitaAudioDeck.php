<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * H4474 stages 1–3 — subhāṣita audio SRS deck (H4474 plan §5, PR #2529 follow-up).
 *
 * Reads the VENDORED static feed resources/data/subhashita_srs_deck.json
 * (59 recordings joined to Böhtlingk *Indische Sprüche* numbers by the
 * H4474 manifest, verified twice — see the handoff's Verifier sections)
 * and creates one SYSTEM SrsDeck + SrsCards, mirroring
 * {@see ImportKoshaSrsDeckB1Demo} / {@see ImportMemriseSrsDeck}:
 * Dictionary / SrsNoteType / SrsDeck / DictionaryWord / SrsCard are all
 * firstOrCreate'd keyed on stable identity, so a re-run never duplicates.
 *
 * Card fields: devanagari (full verse from the recordings/anthology docx when
 * available, else the Böhtlingk saying), iast, translation (RU tātparyam from
 * the anthology), audio (public-disk path — served AFTER `subhashita:push-audio`,
 * MG ruled the recordings are his own 14-09-2026), is_num + audio_id as stable
 * provenance keys.
 *
 * Gated by config('features.subhashita_srs'); OFF by default — with the flag
 * off this command writes nothing.
 *
 *   php artisan subhashita:import-audio-deck
 *   php artisan subhashita:import-audio-deck --dry-run
 */
class ImportSubhashitaAudioDeck extends Command
{
    protected $signature = 'subhashita:import-audio-deck
                            {--dry-run : Report counts only, write nothing}
                            {--path= : Override the feed path (tests only; defaults to the vendored resource file)}';

    protected $description = 'Import the H4474 subhāṣita audio SRS deck into the SRS engine';

    private const FEED_PATH = 'data/subhashita_srs_deck.json';

    private const DECK_SLUG = 'subhashita-audio';

    public function handle(): int
    {
        if (! config('features.subhashita_srs', false)) {
            $this->warn('features.subhashita_srs is OFF — nothing imported. Set SUBHASHITA_SRS=true to enable.');

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

        $this->info('subhashita-audio-srs-deck — '.count($cards).' card(s) in feed');
        $this->line($dryRun ? '[dry-run] no writes will be made' : 'importing...');

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry-run] would import 1 deck, '.count($cards).' card(s).');

            return self::SUCCESS;
        }

        $dictionary = Dictionary::firstOrCreate(
            ['name' => 'subhashita audio'],
            ['description' => 'H4474: subhāṣita recordings joined to Böhtlingk IS numbers (audio manifest H4474).', 'is_active' => true],
        );

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'subhashita_audio'],
            [
                'name' => 'subhāṣita — audio recordings (H4474)',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation', 'audio', 'is_num', 'audio_id'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => self::DECK_SLUG, 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'सुभाषित — аудио (записи MG, Бётлингк IS)',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'H4474: 59 subhāṣita recordings (MG\'s own tapes, ruling 14-09-2026) joined to Böhtlingk Indische Sprüche numbers; audio served from the public disk after subhashita:push-audio.',
            ],
        );

        $imported = 0;
        $existing = 0;

        // Feed is already sorted by (set, su_num) — insertion order keeps the
        // first run's card id order == feed order.
        foreach ($cards as $card) {
            $word = DictionaryWord::firstOrCreate(
                ['dictionary_id' => $dictionary->id, 'iast' => $card['is_iast']],
                [
                    'devanagari' => $card['is_deva'],
                    'translation' => $card['ru'] ?? '',
                    'slug' => 'subhashita-is-'.$card['is_num'],
                ],
            );

            $fields = [
                'devanagari' => $card['verse_deva'] ?? $card['is_deva'],
                'iast' => $card['is_iast'],
                'translation' => $card['ru'] ?? '',
                'audio' => 'srs/subhashita/'.$card['audio_id'].'.mp3',
                'is_num' => $card['is_num'],
                'audio_id' => $card['audio_id'],
            ];

            $srsCard = SrsCard::firstOrCreate(
                ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                [
                    'direction' => 'front_back',
                    'fields' => $fields,
                ],
            );

            $srsCard->wasRecentlyCreated ? $imported++ : $existing++;
        }

        $this->newLine();
        $this->info("Imported 1 deck, {$imported} new card(s), {$existing} already present.");

        return self::SUCCESS;
    }

    /**
     * @return array{id:string,cards:list<array<string,mixed>>}
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
