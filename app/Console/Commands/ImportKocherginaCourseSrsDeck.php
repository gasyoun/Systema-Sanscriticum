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
 * H8211 — the full Kochergina «Учебник санскрита» course export (Memrise
 * course 6502608) -> one SRS deck per textbook lesson, plus the export's
 * intro level. Completes the H1431 lesson-1 pilot into the 40-lesson
 * delivery: level 1 of the export is the textbook's list of grammatical
 * abbreviations (условные сокращения), levels 2..41 are the vocabulary of
 * Занятия I..XL — the level-2 anchor was verified against the digitized
 * textbook in H1431, and the same author structure extends it.
 *
 * Source: database/seeders/data/memrise_6502608/ (manifest.json + level_01
 * .. level_41.csv). Two columns per row: `col_a` (IAST-like headword, a
 * leading `°` marks a compound-stem form) and `col_b` (Russian gloss,
 * sometimes prefixed with a parenthetical grammar-class tag like `(m)`).
 *
 * Same field set as the H1431 lesson-1 deck ({@see
 * ImportKocherginaLesson1SrsDeck}): devanagari/translation_en stay absent
 * from card `fields` since the source has neither — omit, don't invent.
 *
 * Distinct from a plain source_word_id identity in one load-bearing way
 * (the {@see ImportBuhlerParadigmsSrsDeck} lesson): the export contains
 * duplicate headword rows with *conflicting* glosses (e.g. level 24 `labh`
 * "получать" and "получать, брать, добиваться чего либо"). Cards are
 * de-duplicated on deck_id + fields->iast + fields->translation_ru, never
 * on source_word_id — keying on the word would silently collapse the
 * second variant and lose it; here every non-empty source row stays a
 * card, and the variant pairs are documented in the registry instead.
 *
 * The H1431 lesson-1 deck (slug kochergina-lesson-1) is NOT re-created or
 * mutated: decks are firstOrCreate'd by slug, so a run on a database that
 * already has the pilot deck simply finds it (idempotent, no duplicates).
 *
 * Idempotent, same firstOrCreate pattern as the other SRS import commands:
 * re-running never duplicates the note type, decks, words, or cards.
 *
 *   php artisan srs:import-kochergina-course
 *   php artisan srs:import-kochergina-course --dry-run
 */
class ImportKocherginaCourseSrsDeck extends Command
{
    protected $signature = 'srs:import-kochergina-course
                            {--dry-run : Report counts only, write nothing}
                            {--path= : Override the source export directory (tests only)}';

    protected $description = 'Import the Kochergina «Учебник санскрита» course (Memrise 6502608) into per-lesson SRS decks';

    private const DEFAULT_SOURCE = 'database/seeders/data/memrise_6502608';

    private const NOTE_TYPE_KEY = 'kochergina_course';

    /** Export level index that is the textbook's list of grammatical abbreviations. */
    private const INTRO_LEVEL_INDEX = 1;

    /** Export level index of Занятие I (H1431-verified anchor). */
    private const FIRST_LESSON_LEVEL_INDEX = 2;

    public function handle(): int
    {
        $dir = rtrim((string) ($this->option('path') ?: base_path(self::DEFAULT_SOURCE)), '/\\');
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

        $this->info('Kochergina «Учебник санскрита» — course '.$manifest['course_id']);
        $this->line($dryRun ? '[dry-run] no writes will be made' : 'importing...');

        $levelsByIndex = [];
        $totalRows = 0;
        foreach ($manifest['levels'] as $level) {
            $csvPath = $dir.DIRECTORY_SEPARATOR.$level['file'];
            if (! is_file($csvPath)) {
                $this->warn("  skip level {$level['index']} — file not found: {$csvPath}");

                continue;
            }

            $rows = $this->readCsv($csvPath);
            $levelsByIndex[(int) $level['index']] = ['level' => $level, 'rows' => $rows];
            $totalRows += count($rows);
        }

        if ($dryRun) {
            $this->newLine();
            $this->info(sprintf(
                '[dry-run] would import %d deck(s) (level 1 = intro, levels 2+ = lessons), %d card(s).',
                count($levelsByIndex),
                $totalRows,
            ));

            return self::SUCCESS;
        }

        $dictionary = Dictionary::firstOrCreate(
            ['name' => 'Kochergina — «Учебник санскрита»'],
            ['description' => 'V. A. Kochergina, «Учебник санскрита» (1998) — lesson vocabulary, digitized + cross-checked (H1431, H8211).', 'is_active' => true],
        );

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => 'Кочергина — словарные карточки',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation_ru', 'translation_en', 'notes'],
            ],
        );

        $totalNew = 0;
        $totalExisting = 0;
        $totalDecks = 0;

        foreach ($levelsByIndex as $index => $entry) {
            $rows = $entry['rows'];
            [$deckSlug, $deckName, $deckDescription] = $this->deckIdentity($index);

            $deck = SrsDeck::firstOrCreate(
                ['slug' => $deckSlug, 'user_id' => null],
                [
                    'note_type_id' => $noteType->id,
                    'name' => $deckName,
                    'language' => 'sa',
                    'visibility' => 'system',
                    'description' => $deckDescription,
                ],
            );
            $totalDecks++;

            $newThisLevel = 0;
            $existingThisLevel = 0;
            $seenPairs = [];
            $headCounts = array_count_values(array_column($rows, 'iast'));
            $variantHeads = array_keys(array_filter($headCounts, fn (int $c) => $c > 1));

            foreach ($rows as $row) {
                $word = DictionaryWord::firstOrCreate(
                    ['dictionary_id' => $dictionary->id, 'iast' => $row['iast']],
                    ['translation' => $row['translation_ru']],
                );

                $fields = [
                    'iast' => $row['iast'],
                    'translation_ru' => $row['translation_ru'],
                ];
                if ($row['notes'] !== null) {
                    $fields['notes'] = $row['notes'];
                }

                // Exact-duplicate row within one level (same headword AND
                // gloss): the earlier card already carries it.
                $pair = $row['iast']."\u{0000}".$row['translation_ru'];
                if (isset($seenPairs[$pair])) {
                    $existingThisLevel++;

                    continue;
                }
                $seenPairs[$pair] = true;

                // Bühler-pattern identity: same headword with a different
                // gloss is a distinct card, not a collapse of the word.
                $card = SrsCard::query()
                    ->where('deck_id', $deck->id)
                    ->where('fields->iast', $row['iast'])
                    ->where('fields->translation_ru', $row['translation_ru'])
                    ->first();

                if ($card === null) {
                    SrsCard::create([
                        'deck_id' => $deck->id,
                        'source_word_id' => $word->id,
                        'direction' => 'front_back',
                        'fields' => $fields,
                    ]);
                    $newThisLevel++;
                } else {
                    $existingThisLevel++;
                }
            }

            $this->line(sprintf(
                '  level %d -> %s: %d row(s), %d new card(s), %d already present%s',
                $index,
                $deckSlug,
                count($rows),
                $newThisLevel,
                $existingThisLevel,
                $variantHeads === [] ? '' : ' (duplicate headword kept: '.implode(', ', $variantHeads).')',
            ));

            $totalNew += $newThisLevel;
            $totalExisting += $existingThisLevel;
        }

        $this->newLine();
        $this->info("Imported {$totalDecks} deck(s), {$totalNew} new card(s), {$totalExisting} already present.");

        return self::SUCCESS;
    }

    /**
     * Export level index -> deck identity. Level 1 is the intro level (the
     * textbook's list of grammatical abbreviations); level N+1 is Занятие N.
     * Lesson slugs match the H1431 pilot (kochergina-lesson-1) so the
     * already-imported lesson-1 deck is reused, never duplicated.
     *
     * @return list{string,string,string} slug, name, description
     */
    private function deckIdentity(int $levelIndex): array
    {
        if ($levelIndex === self::INTRO_LEVEL_INDEX) {
            return [
                'kochergina-intro',
                'Кочергина — условные сокращения',
                'H8211: условные сокращения грамматики «Учебника санскрита» В. А. Кочергиной — вводный уровень экспорта (Memrise course 6502608, level 1), не словарь урока.',
            ];
        }

        $lesson = $levelIndex - self::FIRST_LESSON_LEVEL_INDEX + 1;
        $roman = $this->roman($lesson);

        return [
            "kochergina-lesson-{$lesson}",
            "Кочергина — Занятие {$roman} (словарь)",
            "H8211: словарь Занятия {$roman} «Учебника санскрита» В. А. Кочергиной (Memrise course 6502608, level {$levelIndex}).",
        ];
    }

    private function roman(int $number): string
    {
        $map = [
            40 => 'XL', 10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];
        $result = '';
        foreach ($map as $value => $glyph) {
            while ($number >= $value) {
                $result .= $glyph;
                $number -= $value;
            }
        }

        return $result;
    }

    /**
     * @return array{course_id:string,course_name:string,source_url:?string,levels:list<array{index:int,name:string,file:string}>}
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

        foreach (['course_id', 'course_name', 'levels'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new RuntimeException("manifest.json missing required key: {$required}");
            }
        }

        return $data;
    }

    /**
     * Same two-column contract and skip rule as the H1431 lesson-1 importer.
     *
     * @return list<array{iast:string,translation_ru:string,notes:?string}>
     */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}");
        }

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false || $header !== ['col_a', 'col_b']) {
            fclose($handle);

            throw new RuntimeException("Unexpected header in {$path} — expected col_a,col_b");
        }

        $rows = [];
        while (($raw = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $iast = trim((string) ($raw[0] ?? ''));
            $gloss = trim((string) ($raw[1] ?? ''));
            if ($iast === '') {
                continue;
            }

            $rows[] = [
                'iast' => $iast,
                'translation_ru' => $gloss,
                'notes' => $this->extractGrammarNote($gloss),
            ];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * Same convention as the H1431 lesson-1 importer: the gloss sometimes
     * leads with a parenthetical grammar-class tag — `(n)`, `(m)`, `(m,n)`
     * — extracted into `notes` when present; the full gloss (tag included)
     * stays intact in `translation_ru` so nothing is lost if the pattern
     * misses a variant.
     */
    private function extractGrammarNote(string $gloss): ?string
    {
        if (preg_match('/^\(([a-z](?:\s*,\s*[a-z])*)\)/', $gloss, $m) === 1) {
            return '('.$m[1].')';
        }

        return null;
    }
}
