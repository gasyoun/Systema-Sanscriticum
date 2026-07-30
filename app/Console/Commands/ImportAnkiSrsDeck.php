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
 * H1970 — import an Anki .apkg export (scripts/anki_apkg_to_srs_export.py output)
 * into the native Systema SRS engine.
 *
 * Same manifest.json + level CSV contract as {@see ImportMemriseSrsDeck}, but
 * with Anki provenance (slug/key prefix `anki-` / `anki_`), optional audio/image
 * media paths stored on the card fields for a future audio UI wave, and
 * romanization mapped into the `iast` identity slot (Latin key; not true IAST).
 *
 * Idempotent: Dictionary / SrsNoteType / SrsDeck / DictionaryWord / SrsCard are
 * firstOrCreate'd on stable keys — re-runs never duplicate.
 *
 *   php artisan srs:import-anki database/seeders/data/anki_454628379
 *   php artisan srs:import-anki … --dry-run
 */
class ImportAnkiSrsDeck extends Command
{
    protected $signature = 'srs:import-anki
                            {path : Directory containing manifest.json + level CSVs (+ optional media/)}
                            {--dry-run : Report counts only, write nothing}';

    protected $description = 'Import an Anki shared-deck export (manifest.json + level CSVs) into the SRS engine';

    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('path'), '/\\');
        $manifestPath = $dir.DIRECTORY_SEPARATOR.'manifest.json';

        if (! is_file($manifestPath)) {
            $this->error("manifest.json not found at {$manifestPath}");

            return self::FAILURE;
        }

        try {
            $manifest = $this->readManifest($manifestPath);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $language = $manifest['language'] ?? 'hi';
        $courseId = (string) $manifest['course_id'];
        $columns = $manifest['columns'];

        $this->info("Anki deck {$courseId} — {$manifest['course_name']} ({$language})");
        $this->line($dryRun ? '[dry-run] no writes will be made' : 'importing...');

        $dictionaryName = "Anki import — deck {$courseId}";
        $dictionary = $dryRun
            ? Dictionary::where('name', $dictionaryName)->first()
            : Dictionary::firstOrCreate(
                ['name' => $dictionaryName],
                ['description' => $manifest['source_url'] ?? null, 'is_active' => true],
            );

        $noteType = $dryRun
            ? SrsNoteType::where('key', "anki_{$courseId}")->first()
            : SrsNoteType::firstOrCreate(
                ['key' => "anki_{$courseId}"],
                [
                    'name' => "Anki — {$manifest['course_name']}",
                    'language' => $language,
                    'fields' => array_keys($columns),
                ],
            );

        $totalWords = 0;
        $totalCards = 0;
        $totalDecks = 0;

        foreach ($manifest['levels'] as $level) {
            $csvPath = $dir.DIRECTORY_SEPARATOR.$level['file'];
            if (! is_file($csvPath)) {
                $this->warn("  skip level {$level['index']} — file not found: {$csvPath}");

                continue;
            }

            $rows = $this->readCsv($csvPath, $columns);

            $deckSlug = "anki-{$courseId}-level-{$level['index']}";
            $deck = null;
            if (! $dryRun) {
                $deck = SrsDeck::firstOrCreate(
                    ['slug' => $deckSlug, 'user_id' => null],
                    [
                        'note_type_id' => $noteType->id,
                        'name' => $level['name'],
                        'language' => $language,
                        'visibility' => 'system',
                        'description' => "Anki shared deck {$courseId}, level {$level['index']} — imported.",
                    ],
                );
                $totalDecks++;
            } else {
                $totalDecks++;
            }

            $wordsThisLevel = 0;
            $cardsThisLevel = 0;

            foreach ($rows as $row) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                if ($dryRun) {
                    $wordsThisLevel++;
                    $cardsThisLevel++;

                    continue;
                }

                $word = $this->upsertWord($dictionary, $row);
                $wordsThisLevel++;

                SrsCard::firstOrCreate(
                    ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                    [
                        'direction' => 'front_back',
                        'fields' => $this->cardFields($row),
                    ],
                );
                $cardsThisLevel++;
            }

            $this->line("  level {$level['index']} ({$level['name']}): {$wordsThisLevel} words, {$cardsThisLevel} cards");
            $totalWords += $wordsThisLevel;
            $totalCards += $cardsThisLevel;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] would import ' : 'Imported ')."{$totalDecks} deck(s), {$totalWords} word row(s), {$totalCards} card(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{course_id:string,course_name:string,source_url:?string,language:string,columns:array<string,string>,levels:list<array{index:int,name:string,file:string}>}
     */
    private function readManifest(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException("Invalid JSON in {$path}");
        }

        foreach (['course_id', 'course_name', 'columns', 'levels'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new RuntimeException("manifest.json missing required key: {$required}");
            }
        }

        return $data;
    }

    /**
     * @param  array<string,string>  $columns
     * @return list<array<string,string>>
     */
    private function readCsv(string $path, array $columns): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false) {
            fclose($handle);

            return [];
        }

        $headerIndex = array_flip($header);
        $rows = [];

        while (($raw = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $row = [];
            foreach ($columns as $field => $csvHeader) {
                $idx = $headerIndex[$csvHeader] ?? null;
                $row[$field] = $idx !== null ? trim((string) ($raw[$idx] ?? '')) : '';
            }
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /** @param array<string,string> $row */
    private function rowIsEmpty(array $row): bool
    {
        return ($row['devanagari'] ?? '') === ''
            && ($row['iast'] ?? '') === ''
            && ($row['cyrillic'] ?? '') === ''
            && ($row['translation'] ?? '') === '';
    }

    /** @param array<string,string> $row */
    private function upsertWord(Dictionary $dictionary, array $row): DictionaryWord
    {
        $identity = [
            'dictionary_id' => $dictionary->id,
        ];
        // Dedup priority: iast (romanization) > devanagari > cyrillic
        if (($row['iast'] ?? '') !== '') {
            $identity['iast'] = $row['iast'];
        } elseif (($row['devanagari'] ?? '') !== '') {
            $identity['devanagari'] = $row['devanagari'];
        } else {
            $identity['cyrillic'] = $row['cyrillic'] ?? '';
        }

        return DictionaryWord::firstOrCreate($identity, [
            'devanagari' => $row['devanagari'] !== '' ? $row['devanagari'] : null,
            'iast' => ($row['iast'] ?? '') !== '' ? $row['iast'] : null,
            'cyrillic' => ($row['cyrillic'] ?? '') !== '' ? $row['cyrillic'] : null,
            'translation' => $row['translation'] ?? '',
        ]);
    }

    /**
     * @param  array<string,string>  $row
     * @return array<string,string|list<string>>
     */
    private function cardFields(array $row): array
    {
        $fields = [];
        foreach (['devanagari', 'iast', 'cyrillic', 'translation', 'audio', 'image', 'note_id', 'tags'] as $key) {
            if (($row[$key] ?? '') !== '') {
                $fields[$key] = $row[$key];
            }
        }
        if (($row['alt_answers'] ?? '') !== '') {
            $fields['alt_answers'] = array_values(array_filter(array_map('trim', explode('|', $row['alt_answers']))));
        }

        return $fields;
    }
}
