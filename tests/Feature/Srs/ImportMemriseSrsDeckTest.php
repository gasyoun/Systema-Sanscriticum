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
 * H569 P1 — Memrise export -> SRS import. Runs against the sample fixture at
 * tests/fixtures/memrise_sample/ (NOT the real course export, which needs a
 * human-run P0 browser export — see database/seeders/data/memrise_6679375/README.md).
 */
class ImportMemriseSrsDeckTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/fixtures/memrise_sample');
    }

    public function test_imports_note_type_decks_words_and_cards(): void
    {
        Artisan::call('srs:import-memrise', ['path' => $this->fixturePath()]);

        $noteType = SrsNoteType::where('key', 'memrise_TEST0001')->first();
        $this->assertNotNull($noteType);
        $this->assertSame('sa', $noteType->language);

        $decks = SrsDeck::where('slug', 'like', 'memrise-TEST0001-level-%')->get();
        $this->assertCount(2, $decks);
        foreach ($decks as $deck) {
            $this->assertSame('system', $deck->visibility);
            $this->assertSame($noteType->id, $deck->note_type_id);
        }

        // 4 distinct words across both levels: satya, dharma, diksha, yoga
        // (satya appears in both level_01 and level_02 -- must dedup to one row).
        $this->assertSame(4, DictionaryWord::count());

        $satya = DictionaryWord::where('iast', 'satya')->firstOrFail();
        $this->assertSame('truth', $satya->translation);

        $deck1 = SrsDeck::where('slug', 'memrise-TEST0001-level-1')->firstOrFail();
        $deck2 = SrsDeck::where('slug', 'memrise-TEST0001-level-2')->firstOrFail();
        $this->assertCount(3, $deck1->cards);
        $this->assertCount(2, $deck2->cards);

        // satya's card in level 2 points at the SAME DictionaryWord row as level 1.
        $satyaCardLevel2 = $deck2->cards()->where('source_word_id', $satya->id)->first();
        $this->assertNotNull($satyaCardLevel2);

        // alt_answers is parsed into a list on the card that has it.
        $satyaCardLevel1 = $deck1->cards()->where('source_word_id', $satya->id)->first();
        $this->assertSame(['truthfulness', 'истина'], $satyaCardLevel1->fields['alt_answers']);

        // The devanagari-less row (diksha) dedupes on cyrillic, and its card
        // field set correctly omits the empty devanagari/iast keys.
        $diksha = DictionaryWord::where('cyrillic', 'дикша')->firstOrFail();
        $this->assertNull($diksha->iast);
        $dikshaCard = SrsCard::where('source_word_id', $diksha->id)->firstOrFail();
        $this->assertArrayNotHasKey('devanagari', $dikshaCard->fields);
        $this->assertArrayNotHasKey('iast', $dikshaCard->fields);
        $this->assertSame('дикша', $dikshaCard->fields['cyrillic']);

        $dictionary = Dictionary::where('name', 'Memrise import — course TEST0001')->firstOrFail();
        $this->assertSame($dictionary->id, $satya->dictionary_id);
    }

    public function test_is_idempotent_on_rerun(): void
    {
        Artisan::call('srs:import-memrise', ['path' => $this->fixturePath()]);
        Artisan::call('srs:import-memrise', ['path' => $this->fixturePath()]);

        $this->assertSame(1, SrsNoteType::where('key', 'memrise_TEST0001')->count());
        $this->assertSame(2, SrsDeck::where('slug', 'like', 'memrise-TEST0001-level-%')->count());
        $this->assertSame(4, DictionaryWord::count());
        $this->assertSame(5, SrsCard::count()); // 3 (level 1) + 2 (level 2), no duplicates
    }

    public function test_dry_run_writes_nothing(): void
    {
        Artisan::call('srs:import-memrise', ['path' => $this->fixturePath(), '--dry-run' => true]);

        $this->assertSame(0, SrsNoteType::count());
        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, DictionaryWord::count());
        $this->assertSame(0, SrsCard::count());
    }

    public function test_missing_manifest_fails_cleanly(): void
    {
        $exit = Artisan::call('srs:import-memrise', ['path' => sys_get_temp_dir()]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, SrsDeck::count());
    }
}
