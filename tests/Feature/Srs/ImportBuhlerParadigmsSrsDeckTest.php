<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\Dictionary;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1990 — Bühler grammar-tables (Memrise 6517849) paradigm cells -> a
 * dedicated Bühler paradigm-drills SRS deck. Runs against a 7-row sample
 * fixture (tests/fixtures/srs_buhler_paradigms/), not the full 78-row
 * production export.
 */
class ImportBuhlerParadigmsSrsDeckTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/fixtures/srs_buhler_paradigms');
    }

    public function test_imports_note_type_deck_and_cards(): void
    {
        Artisan::call('srs:import-buhler-paradigms', ['--path' => $this->fixturePath()]);

        $noteType = SrsNoteType::where('key', 'buhler_paradigm_cell')->first();
        $this->assertNotNull($noteType);
        $this->assertSame('sa', $noteType->language);
        $this->assertSame(['form', 'label', 'lemma', 'stem_class'], $noteType->fields);

        $deck = SrsDeck::where('slug', 'buhler-paradigm-drills')->first();
        $this->assertNotNull($deck);
        $this->assertSame('system', $deck->visibility);
        $this->assertSame($noteType->id, $deck->note_type_id);
        $this->assertCount(7, $deck->cards);

        $this->assertNotNull(Dictionary::where('name', 'Bühler paradigm drills — Memrise 6517849')->first());
    }

    public function test_card_carries_lemma_and_stem_class_metadata(): void
    {
        Artisan::call('srs:import-buhler-paradigms', ['--path' => $this->fixturePath()]);

        $deck = SrsDeck::where('slug', 'buhler-paradigm-drills')->firstOrFail();
        $card = SrsCard::where('deck_id', $deck->id)->where('fields->form', 'bhānunā')->firstOrFail();

        $this->assertSame('bhānunā', $card->fields['form']);
        $this->assertSame('I., sg.', $card->fields['label']);
        $this->assertSame('bhānuḥ', $card->fields['lemma']);
        $this->assertSame('u-stem masc.', $card->fields['stem_class']);
    }

    public function test_duplicate_form_with_different_label_creates_two_cards(): void
    {
        Artisan::call('srs:import-buhler-paradigms', ['--path' => $this->fixturePath()]);

        $deck = SrsDeck::where('slug', 'buhler-paradigm-drills')->firstOrFail();
        $cards = SrsCard::where('deck_id', $deck->id)->where('fields->form', 'giraḥ')->get();

        $this->assertCount(2, $cards);
        $labels = $cards->pluck('fields.label')->sort()->values()->all();
        $this->assertSame(['Abl., Gen., sg.', 'N., A., V., pl.'], $labels);
        foreach ($cards as $card) {
            $this->assertSame('gir', $card->fields['lemma']);
            $this->assertSame('r-stem (consonant)', $card->fields['stem_class']);
        }
    }

    public function test_is_idempotent_on_rerun(): void
    {
        Artisan::call('srs:import-buhler-paradigms', ['--path' => $this->fixturePath()]);
        Artisan::call('srs:import-buhler-paradigms', ['--path' => $this->fixturePath()]);

        $this->assertSame(1, SrsNoteType::where('key', 'buhler_paradigm_cell')->count());
        $this->assertSame(1, SrsDeck::where('slug', 'buhler-paradigm-drills')->count());
        $this->assertSame(7, SrsCard::count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        Artisan::call('srs:import-buhler-paradigms', ['--path' => $this->fixturePath(), '--dry-run' => true]);

        $this->assertSame(0, SrsNoteType::count());
        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, SrsCard::count());
    }

    public function test_missing_source_fails_cleanly(): void
    {
        $exit = Artisan::call('srs:import-buhler-paradigms', ['--path' => sys_get_temp_dir().'/does-not-exist']);

        $this->assertSame(1, $exit);
        $this->assertSame(0, SrsDeck::count());
    }

    public function test_public_deck_url_returns_200_when_enabled(): void
    {
        putenv('SRS_ENABLED=true');
        $_ENV['SRS_ENABLED'] = 'true';
        $_SERVER['SRS_ENABLED'] = 'true';
        config(['srs.enabled' => true]);

        Artisan::call('srs:import-buhler-paradigms', ['--path' => $this->fixturePath()]);

        $this->get('/koloda/buhler-paradigm-drills')->assertOk();

        putenv('SRS_ENABLED');
        unset($_ENV['SRS_ENABLED'], $_SERVER['SRS_ENABLED']);
    }
}
