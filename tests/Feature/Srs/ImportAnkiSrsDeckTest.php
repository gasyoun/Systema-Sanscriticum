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
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * H1970 — Anki .apkg export → SRS import.
 * Fixture: tests/fixtures/anki_sample/ (tiny synthetic; not the real Hindi Core deck).
 */
class ImportAnkiSrsDeckTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/fixtures/anki_sample');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_imports_note_type_deck_words_and_cards(): void
    {
        Artisan::call('srs:import-anki', ['path' => $this->fixturePath()]);

        $noteType = SrsNoteType::where('key', 'anki_TEST4546')->first();
        $this->assertNotNull($noteType);
        $this->assertSame('hi', $noteType->language);

        $deck = SrsDeck::where('slug', 'anki-TEST4546-level-1')->first();
        $this->assertNotNull($deck);
        $this->assertSame('system', $deck->visibility);
        $this->assertSame($noteType->id, $deck->note_type_id);

        $this->assertSame(3, DictionaryWord::count());
        $this->assertSame(3, SrsCard::count());

        $hafta = DictionaryWord::where('devanagari', 'हफ्ता')->firstOrFail();
        $this->assertSame('week', $hafta->translation);
        $this->assertSame('hafTaa', $hafta->iast);

        $card = SrsCard::where('source_word_id', $hafta->id)->firstOrFail();
        $this->assertSame('हफ्ता', $card->fields['devanagari']);
        $this->assertSame('hafTaa', $card->fields['iast']);
        $this->assertSame('week', $card->fields['translation']);
        $this->assertSame('srs/anki_TEST4546/284860.mp3', $card->fields['audio']);
        $this->assertSame('srs/anki_TEST4546/22019_96square.jpg', $card->fields['image']);

        Storage::disk('public')->assertExists('srs/anki_TEST4546/284860.mp3');
        Storage::disk('public')->assertExists('srs/anki_TEST4546/22019_96square.jpg');

        $dictionary = Dictionary::where('name', 'Anki import — deck TEST4546')->firstOrFail();
        $this->assertSame($dictionary->id, $hafta->dictionary_id);
    }

    public function test_is_idempotent_on_rerun(): void
    {
        Artisan::call('srs:import-anki', ['path' => $this->fixturePath()]);
        Artisan::call('srs:import-anki', ['path' => $this->fixturePath()]);

        $this->assertSame(1, SrsNoteType::where('key', 'anki_TEST4546')->count());
        $this->assertSame(1, SrsDeck::where('slug', 'anki-TEST4546-level-1')->count());
        $this->assertSame(3, DictionaryWord::count());
        $this->assertSame(3, SrsCard::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Artisan::call('srs:import-anki', ['path' => $this->fixturePath(), '--dry-run' => true]);

        $this->assertSame(0, SrsNoteType::count());
        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, DictionaryWord::count());
        $this->assertSame(0, SrsCard::count());
    }

    public function test_missing_manifest_fails_cleanly(): void
    {
        $exit = Artisan::call('srs:import-anki', ['path' => sys_get_temp_dir()]);

        $this->assertSame(1, $exit);
        $this->assertSame(0, SrsDeck::count());
    }
}
