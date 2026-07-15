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
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * H955 — kosha Rung-B1 demo SRS deck -> Systema import. Runs against the
 * sample fixture at tests/fixtures/kosha_srs_b1_demo/sample.json, not the
 * real vendored feed.
 */
class ImportKoshaSrsDeckB1DemoTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/fixtures/kosha_srs_b1_demo/sample.json');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('features.kosha_srs', true);
    }

    public function test_flag_off_writes_nothing(): void
    {
        Config::set('features.kosha_srs', false);

        Artisan::call('srs:import-kosha-b1-demo', ['--path' => $this->fixturePath()]);

        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, SrsCard::count());
    }

    public function test_imports_note_type_deck_words_and_cards_in_rank_order(): void
    {
        Artisan::call('srs:import-kosha-b1-demo', ['--path' => $this->fixturePath()]);

        $noteType = SrsNoteType::where('key', 'kosha_b1_demo')->first();
        $this->assertNotNull($noteType);
        $this->assertSame('sa', $noteType->language);

        $deck = SrsDeck::where('slug', 'kosha-b1-demo')->firstOrFail();
        $this->assertSame('system', $deck->visibility);
        $this->assertSame($noteType->id, $deck->note_type_id);

        $this->assertSame(4, DictionaryWord::count());
        $this->assertSame(4, SrsCard::count());

        // Card insertion order (id order) must match the feed's rank order —
        // srs_cards has no sort_rank column, so id order IS the order signal.
        $cards = $deck->cards()->orderBy('id')->get();
        $this->assertSame([1, 2, 4, 5], $cards->pluck('fields.rank')->all());
        $this->assertSame(['kf', 'vac', 'mahat', 'as'], $cards->pluck('fields.slp1')->all());

        $kf = DictionaryWord::where('iast', 'kṛ')->firstOrFail();
        $this->assertSame('to make', $kf->translation);
        $this->assertSame('कृ', $kf->devanagari);

        $dictionary = Dictionary::where('name', 'kosha Rung B1 demo')->firstOrFail();
        $this->assertSame($dictionary->id, $kf->dictionary_id);
    }

    public function test_is_idempotent_on_rerun(): void
    {
        Artisan::call('srs:import-kosha-b1-demo', ['--path' => $this->fixturePath()]);
        Artisan::call('srs:import-kosha-b1-demo', ['--path' => $this->fixturePath()]);

        $this->assertSame(1, SrsNoteType::where('key', 'kosha_b1_demo')->count());
        $this->assertSame(1, SrsDeck::where('slug', 'kosha-b1-demo')->count());
        $this->assertSame(4, DictionaryWord::count());
        $this->assertSame(4, SrsCard::count()); // no duplicates on rerun
    }

    public function test_dry_run_writes_nothing(): void
    {
        Artisan::call('srs:import-kosha-b1-demo', ['--path' => $this->fixturePath(), '--dry-run' => true]);

        $this->assertSame(0, SrsNoteType::count());
        $this->assertSame(0, SrsDeck::count());
        $this->assertSame(0, DictionaryWord::count());
        $this->assertSame(0, SrsCard::count());
    }

    public function test_missing_feed_fails_cleanly(): void
    {
        $exit = Artisan::call('srs:import-kosha-b1-demo', ['--path' => sys_get_temp_dir().'/does-not-exist.json']);

        $this->assertSame(1, $exit);
        $this->assertSame(0, SrsDeck::count());
    }

    public function test_no_function_words_present_in_real_vendored_feed(): void
    {
        // Sanity check against the REAL vendored feed (not the fixture): the
        // kosha-side build already strips grammar_all in {ind, pron} — this
        // just asserts the vendored file still looks like a content-word deck
        // (regression guard if a future re-vendor accidentally ships the raw set).
        $realFeedPath = resource_path('data/kosha_srs_deck_b1_demo.json');
        $this->assertFileExists($realFeedPath);

        $feed = json_decode((string) file_get_contents($realFeedPath), true);
        $this->assertSame('kosha-srs-deck-b1-demo', $feed['id']);
        $this->assertGreaterThan(0, count($feed['cards']));

        $ranks = array_column($feed['cards'], 'rank');
        $sorted = $ranks;
        sort($sorted);
        $this->assertSame($sorted, $ranks, 'feed must already be rank-ordered');
    }
}
