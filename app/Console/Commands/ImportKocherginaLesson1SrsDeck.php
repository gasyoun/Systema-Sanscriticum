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
 * H1431 — Kochergina «Учебник санскрита», Занятие I (lesson 1) vocabulary ->
 * a dedicated system SrsDeck, separate from the generic Memrise-course import
 * pipeline ({@see ImportMemriseSrsDeck}).
 *
 * Source: database/seeders/data/memrise_6502608/level_02.csv (verified against
 * the digitized textbook's Упражнение II — see the H1431 handoff for the
 * cross-check). Two columns only: `col_a` (IAST-like headword, a leading `°`
 * marks a compound-stem form) and `col_b` (Russian gloss, sometimes prefixed
 * with a parenthetical grammar-class tag like `(m)`/`(n)`/`(m,n)`).
 *
 * Schema-maps onto the roadmap P1 note-type field set (devanagari, iast,
 * translation_ru, translation_en, notes) — `devanagari`/`translation_en` stay
 * absent from card `fields` for every row here since the source has neither
 * (same "omit, don't invent" convention as {@see ImportMemriseSrsDeck}'s
 * cyrillic-less rows).
 *
 * Idempotent, same firstOrCreate/upsert pattern as the other Srs import
 * commands: re-running never duplicates the note type, deck, words, or cards.
 *
 *   php artisan srs:import-kochergina-lesson1
 *   php artisan srs:import-kochergina-lesson1 --dry-run
 */
class ImportKocherginaLesson1SrsDeck extends Command
{
    protected $signature = 'srs:import-kochergina-lesson1
                            {--dry-run : Report counts only, write nothing}
                            {--path= : Override the source CSV path (tests only)}';

    protected $description = 'Import Kochergina «Учебник санскрита» lesson 1 (Занятие I) vocabulary into a dedicated SRS deck';

    private const DEFAULT_SOURCE = 'database/seeders/data/memrise_6502608/level_02.csv';

    private const DECK_SLUG = 'kochergina-lesson-1';

    private const NOTE_TYPE_KEY = 'kochergina_l1';

    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: base_path(self::DEFAULT_SOURCE));

        try {
            $rows = $this->readCsv($path);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $this->info('Kochergina lesson 1 — '.count($rows).' row(s) in source');
        $this->line($dryRun ? '[dry-run] no writes will be made' : 'importing...');

        if ($dryRun) {
            $this->newLine();
            $this->info('[dry-run] would import 1 deck, '.count($rows).' word(s)/card(s).');

            return self::SUCCESS;
        }

        $dictionary = Dictionary::firstOrCreate(
            ['name' => 'Kochergina — «Учебник санскрита»'],
            ['description' => 'V. A. Kochergina, «Учебник санскрита» (1998) — lesson vocabulary, digitized + cross-checked (H1431).', 'is_active' => true],
        );

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => 'Кочергина — Занятие I',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation_ru', 'translation_en', 'notes'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => self::DECK_SLUG, 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Кочергина — Занятие I (словарь)',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'H1431: словарь Занятия I «Учебника санскрита» В. А. Кочергиной — '
                    .'отдельная колода, независимая от Memrise-импорта того же курса.',
            ],
        );

        $imported = 0;
        $existing = 0;

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

            $card = SrsCard::firstOrCreate(
                ['deck_id' => $deck->id, 'source_word_id' => $word->id],
                ['direction' => 'front_back', 'fields' => $fields],
            );

            $card->wasRecentlyCreated ? $imported++ : $existing++;
        }

        $this->newLine();
        $this->info("Imported 1 deck, {$imported} new card(s), {$existing} already present.");

        return self::SUCCESS;
    }

    /**
     * @return list<array{iast:string,translation_ru:string,notes:?string}>
     */
    private function readCsv(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Source CSV not found at {$path}");
        }

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
     * The textbook's gloss column sometimes leads with a parenthetical
     * grammar-class tag — `(n)`, `(m)`, `(m,n)`, `(m, n)` — before the actual
     * Russian translation. Extracted into `notes` when present; the full gloss
     * (tag included) still stays intact in `translation_ru` so nothing is lost
     * if the extraction pattern misses a variant.
     */
    private function extractGrammarNote(string $gloss): ?string
    {
        if (preg_match('/^\(([a-z](?:\s*,\s*[a-z])*)\)/', $gloss, $m) === 1) {
            return '('.$m[1].')';
        }

        return null;
    }
}
