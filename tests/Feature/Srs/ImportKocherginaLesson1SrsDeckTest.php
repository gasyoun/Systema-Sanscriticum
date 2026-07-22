<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1431 — Kochergina lesson 1 -> a dedicated SRS deck, separate from the
 * generic Memrise import pipeline. Runs against a 5-row sample fixture
 * (tests/fixtures/srs_kochergina_lesson1/sample.csv), not the full 41-row
 * production level_02.csv.
 */
class ImportKocherginaLesson1SrsDeckTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/fixtures/srs_kochergina_lesson1/sample.csv');
    }

    public function test_imports_note_type_deck_words_and_cards(): void
    {
        Artisan::call('srs:import-kochergina-lesson1', ['--path' => $this->fixturePath()]);

        $noteType = SrsNoteType::where('key', 'kochergina_l1')->first();
        $this->assertNotNull($noteType);
        $this->assertSame('sa', $noteType->language);
        $this->assertSame(
            ['devanagari', 'iast', 'translation_ru', 'translation_en', 'notes'],
            $noteType->fields,
        );

        $deck = SrsDeck::where('slug', 'kochergina-lesson-1')->first();
        $this->assertNotNull($deck);
        $this->assertSame('system', $deck->visibility);
        $this->assertSame($noteType->id, $deck->note_type_id);
        $this->assertCount(5, $deck->cards);

        $dictionary = Dictionary::where('name', 'Kochergina — «Учебник санскрита»')->firstOrFail();
        $this->assertSame(5, DictionaryWord::where('dictionary_id', $dictionary->id)->count());
    }

    public function test_grammar_tag_is_extracted_into_notes_without_dropping_full_gloss(): void
    {
        Artisan::call('srs:import-kochergina-lesson1', ['--path' => $this->fixturePath()]);

        $kata = DictionaryWord::where('iast', 'kaṭa')->firstOrFail();
        $kataCard = SrsCard::where('source_word_id', $kata->id)->firstOrFail();
        $this->assertSame('(m) циновка', $kataCard->fields['translation_ru']);
        $this->assertSame('(m)', $kataCard->fields['notes']);

        $dama = DictionaryWord::where('iast', 'dama')->firstOrFail();
        $damaCard = SrsCard::where('source_word_id', $dama->id)->firstOrFail();
        $this->assertSame('(m,n)', $damaCard->fields['notes']);
    }

    public function test_untagged_rows_omit_notes_key(): void
    {
        Artisan::call('srs:import-kochergina-lesson1', ['--path' => $this->fixturePath()]);

        $ca = DictionaryWord::where('iast', 'ca')->firstOrFail();
        $caCard = SrsCard::where('source_word_id', $ca->id)->firstOrFail();
        $this->assertArrayNotHasKey('notes', $caCard->fields);
        $this->assertArrayNotHasKey('devanagari', $caCard->fields);
        $this->assertArrayNotHasKey('translation_en', $caCard->fields);
    }

    public function test_compound_stem_prefix_is_preserved_on_the_headword(): void
    {
        Artisan::call('srs:import-kochergina-lesson1', ['--path' => $this->fixturePath()]);

        $ga = DictionaryWord::where('iast', '°ga')->first();
        $this->assertNotNull($ga);
        $this->assertSame('идущий', $ga->translation);
    }

    public function test_is_idempotent_on_rerun(): void
    {
        Artisan::call('srs:import-kochergina-lesson1', ['--path' => $this->fixturePath()]);
        Artisan::call('srs:import-kochergina-lesson1', ['--path' => $this->fixturePath()]);

        $this->assertSame(1, SrsNoteType::where('key', 'kochergina_l1')->count());
        $this->assertSame(1, SrsDeck::where('slug', 'kochergina-lesson-1')->count());
        $this->assertSame(5, DictionaryWord::count());
        $this->assertSame(5, SrsCard::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Artisan::call('srs:import-kochergina-lesson1', ['--path' => $this->fixturePath(), '--dry-run' => true]);

        $this->assertSame(0, SrsNoteType::count());
        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, DictionaryWord::count());
        $this->assertSame(0, SrsCard::count());
    }

    public function test_missing_source_fails_cleanly(): void
    {
        $exit = Artisan::call('srs:import-kochergina-lesson1', ['--path' => sys_get_temp_dir().'/does-not-exist.csv']);

        $this->assertSame(1, $exit);
        $this->assertSame(0, SrsDeck::count());
    }
}
