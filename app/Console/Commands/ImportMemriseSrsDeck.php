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
 * H569 P1 — import a Memrise export (P0's output) into the SRS engine.
 *
 * Reads a directory containing `manifest.json` (course metadata + per-level CSV
 * files + a `columns` map from field name -> CSV header) and one CSV per level.
 * The `columns` map is what makes this import robust to whatever the real P0
 * export's header names turn out to be: nothing here is hardcoded to a guessed
 * Memrise column layout, everything is read through the manifest.
 *
 * Idempotent, same pattern as {@see SrsSanskritDeckSeeder}:
 * Dictionary / SrsNoteType / SrsDeck / DictionaryWord / SrsCard are all
 * firstOrCreate'd keyed on stable identity, so a re-run never duplicates.
 *
 *   php artisan srs:import-memrise database/seeders/data/memrise_6679375
 *   php artisan srs:import-memrise ... --dry-run
 */
class ImportMemriseSrsDeck extends Command
{
    protected $signature = 'srs:import-memrise
                            {path : Directory containing manifest.json + level CSVs}
                            {--dry-run : Report counts only, write nothing}';

    protected $description = 'Import a Memrise course export (manifest.json + level CSVs) into the SRS engine';

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
        $language = $manifest['language'] ?? 'sa';
        $courseId = (string) $manifest['course_id'];
        $columns = $manifest['columns'];

        $this->info("Course {$courseId} — {$manifest['course_name']} ({$language})");
        $this->line($dryRun ? '[dry-run] no writes will be made' : 'importing...');

        $dictionary = $dryRun
            ? Dictionary::where('name', "Memrise import — course {$courseId}")->first()
            : Dictionary::firstOrCreate(
                ['name' => "Memrise import — course {$courseId}"],
                ['description' => $manifest['source_url'] ?? null, 'is_active' => true],
            );

        $noteType = $dryRun
            ? SrsNoteType::where('key', "memrise_{$courseId}")->first()
            : SrsNoteType::firstOrCreate(
                ['key' => "memrise_{$courseId}"],
                [
                    'name' => "Memrise — {$manifest['course_name']}",
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

            $deckSlug = "memrise-{$courseId}-level-{$level['index']}";
            $deck = null;
            if (! $dryRun) {
                $deck = SrsDeck::firstOrCreate(
                    ['slug' => $deckSlug, 'user_id' => null],
                    [
                        'note_type_id' => $noteType->id,
                        'name' => $level['name'],
                        'language' => $language,
                        'visibility' => 'system',
                        'description' => "Memrise course {$courseId}, level {$level['index']} — imported.",
                    ],
                );
                $totalDecks++;
            } else {
                $totalDecks++;
            }

            $wordsThisLevel = 0;
            $cardsThisLevel = 0;

            foreach ($rows as $row) {
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
     * Read a CSV keyed by manifest `columns` (field => CSV header), independent
     * of column order or extra columns the real export might carry.
     *
     * @param  array<string,string>  $columns
     * @return list<array<string,string>> rows keyed by field name (e.g. 'devanagari', 'iast', 'translation', 'alt_answers')
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
    private function upsertWord(Dictionary $dictionary, array $row): DictionaryWord
    {
        $identity = [
            'dictionary_id' => $dictionary->id,
        ];
        // Dedup priority: iast > devanagari > cyrillic — the most stable ASCII
        // key first, same order DictionaryWord::makeHeadwordSlug() prefers.
        if (($row['iast'] ?? '') !== '') {
            $identity['iast'] = $row['iast'];
        } elseif (($row['devanagari'] ?? '') !== '') {
            $identity['devanagari'] = $row['devanagari'];
        } else {
            $identity['cyrillic'] = $row['cyrillic'] ?? '';
        }

        return DictionaryWord::firstOrCreate($identity, [
            'devanagari' => $row['devanagari'] ?? null,
            'cyrillic' => $row['cyrillic'] ?? null,
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
        foreach (['devanagari', 'iast', 'cyrillic', 'translation'] as $key) {
            if (($row[$key] ?? '') !== '') {
                $fields[$key] = $row[$key];
            }
        }
        // Alt answers feed the P2 typing-mode fuzzy match; stored pipe-separated
        // in the export, exploded here into a plain list.
        if (($row['alt_answers'] ?? '') !== '') {
            $fields['alt_answers'] = array_values(array_filter(array_map('trim', explode('|', $row['alt_answers']))));
        }

        return $fields;
    }
}
