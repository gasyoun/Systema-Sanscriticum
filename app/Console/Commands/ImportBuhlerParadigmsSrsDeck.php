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
 * H1990 — Bühler grammar-tables Memrise course (6517849) -> a dedicated SRS
 * deck, Option A per ROADMAP_GRAMMAR_TABLES_BUHLER_MEMRISE_SRS_2026.md §P1:
 * one card per inflected form, tagged with `stem_class` + `lemma` metadata
 * so a later Option B (full-paradigm grid) can be built without re-importing.
 *
 * Source rows are paradigm cells, not headword->translation pairs: `col_a`
 * is the inflected form, `col_b` is "`<lemma>` `<case/number label>`" (e.g.
 * `bhānave` -> "bhānuḥ D., sg."). `stem_class` is looked up by lemma (5
 * lemmas total across the export's 5 levels) rather than by level index, so
 * it stays correct even if levels are reordered or re-exported.
 *
 * Distinct from {@see ImportMemriseSrsDeck} in one load-bearing way: the
 * same inflected form can legitimately appear twice in one level with two
 * different case/number labels (e.g. level 5 `giraḥ` = both "Abl., Gen., sg."
 * and "N., A., V., pl."), so cards are de-duplicated on
 * deck_id + fields->form + fields->label, never on source_word_id — keying
 * on the word would silently collapse the second cell into the first.
 *
 *   php artisan srs:import-buhler-paradigms
 *   php artisan srs:import-buhler-paradigms --dry-run
 */
class ImportBuhlerParadigmsSrsDeck extends Command
{
    protected $signature = 'srs:import-buhler-paradigms
                            {--dry-run : Report counts only, write nothing}
                            {--path= : Override the source export directory (tests only)}';

    protected $description = 'Import the Bühler grammar-tables Memrise export (6517849) into a dedicated Bühler paradigm-drills SRS deck';

    private const DEFAULT_SOURCE = 'database/seeders/data/memrise_6517849';

    private const DECK_SLUG = 'buhler-paradigm-drills';

    private const NOTE_TYPE_KEY = 'buhler_paradigm_cell';

    /** Lemma -> human-readable stem class, derived from the export's own 5 lemmas (H1990 handoff). */
    private const STEM_CLASSES = [
        'bhānuḥ' => 'u-stem masc.',
        'madhu' => 'u-stem neut.',
        'dhī' => 'ī-stem fem.',
        'aham' => 'pronoun (aham)',
        'gir' => 'r-stem (consonant)',
    ];

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

        $this->info('Bühler paradigm tables — course '.$manifest['course_id']);
        $this->line($dryRun ? '[dry-run] no writes will be made' : 'importing...');

        $rowsByLevel = [];
        $totalRows = 0;
        foreach ($manifest['levels'] as $level) {
            $csvPath = $dir.DIRECTORY_SEPARATOR.$level['file'];
            if (! is_file($csvPath)) {
                $this->warn("  skip level {$level['index']} — file not found: {$csvPath}");

                continue;
            }
            $rows = $this->readCsv($csvPath);
            $rowsByLevel[] = ['level' => $level, 'rows' => $rows];
            $totalRows += count($rows);
        }

        if ($dryRun) {
            $this->newLine();
            $this->info("[dry-run] would import 1 deck, {$totalRows} card(s).");

            return self::SUCCESS;
        }

        $dictionary = Dictionary::firstOrCreate(
            ['name' => 'Bühler paradigm drills — Memrise 6517849'],
            ['description' => $manifest['source_url'] ?? null, 'is_active' => true],
        );

        $noteType = SrsNoteType::firstOrCreate(
            ['key' => self::NOTE_TYPE_KEY],
            [
                'name' => 'Бюлер — парадигмы склонения',
                'language' => 'sa',
                'fields' => ['form', 'label', 'lemma', 'stem_class'],
            ],
        );

        $deck = SrsDeck::firstOrCreate(
            ['slug' => self::DECK_SLUG, 'user_id' => null],
            [
                'note_type_id' => $noteType->id,
                'name' => 'Бюлер — таблицы склонения',
                'language' => 'sa',
                'visibility' => 'system',
                'description' => 'H1990: парадигмы склонения из курса Memrise 6517849 (Бюлер, «Руководство к элементарному курсу санскритского языка») — одна карточка на словоформу.',
            ],
        );

        $imported = 0;
        $existing = 0;

        foreach ($rowsByLevel as $entry) {
            foreach ($entry['rows'] as $row) {
                [$lemma, $label] = $this->splitLemmaAndLabel($row['col_b']);
                $form = $row['col_a'];

                $word = DictionaryWord::firstOrCreate(
                    ['dictionary_id' => $dictionary->id, 'iast' => $form],
                    ['translation' => $label],
                );

                $fields = [
                    'form' => $form,
                    'label' => $label,
                    'lemma' => $lemma,
                    'stem_class' => self::STEM_CLASSES[$lemma] ?? $lemma,
                ];

                $card = SrsCard::where('deck_id', $deck->id)
                    ->where('fields->form', $form)
                    ->where('fields->label', $label)
                    ->first();

                if ($card === null) {
                    SrsCard::create([
                        'deck_id' => $deck->id,
                        'source_word_id' => $word->id,
                        'direction' => 'front_back',
                        'fields' => $fields,
                    ]);
                    $imported++;
                } else {
                    $existing++;
                }
            }
        }

        $this->newLine();
        $this->info("Imported 1 deck, {$imported} new card(s), {$existing} already present.");

        return self::SUCCESS;
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

        foreach (['course_id', 'levels'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new RuntimeException("manifest.json missing required key: {$required}");
            }
        }

        return $data;
    }

    /**
     * @return list<array{col_a:string,col_b:string}>
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
            $colA = trim((string) ($raw[0] ?? ''));
            $colB = trim((string) ($raw[1] ?? ''));
            if ($colA === '' || $colB === '') {
                continue;
            }
            $rows[] = ['col_a' => $colA, 'col_b' => $colB];
        }

        fclose($handle);

        return $rows;
    }

    /**
     * `col_b` is "`<lemma>` `<case/number label>`" — the lemma is always the
     * first whitespace-delimited token (a single IAST word, no internal
     * spaces in this export), the label is everything after it.
     *
     * @return array{0:string,1:string} [lemma, label]
     */
    private function splitLemmaAndLabel(string $colB): array
    {
        $parts = preg_split('/\s+/', $colB, 2);
        $lemma = $parts[0] ?? $colB;
        $label = $parts[1] ?? '';

        return [$lemma, $label];
    }
}
