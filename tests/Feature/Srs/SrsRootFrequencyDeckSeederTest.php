<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use Database\Seeders\SrsRootFrequencyDeckSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * H1280 (D4) — SRS root-frequency deck. Runs against a 10-root sample fixture
 * (tests/fixtures/srs_roots_frequency/sample.tsv, the real fixture's own top
 * 10 rows), not the full 570-root production fixture.
 */
class SrsRootFrequencyDeckSeederTest extends TestCase
{
    use RefreshDatabase;

    private function fixturePath(): string
    {
        return base_path('tests/fixtures/srs_roots_frequency/sample.tsv');
    }

    private function makeSeeder(): SrsRootFrequencyDeckSeeder
    {
        return new SrsRootFrequencyDeckSeeder($this->fixturePath(), null);
    }

    public function test_seeds_note_type_deck_and_cards_in_frequency_order(): void
    {
        $this->makeSeeder()->run();

        $noteType = SrsNoteType::where('key', 'sanskrit_root')->firstOrFail();
        $this->assertSame('sa', $noteType->language);
        $this->assertSame(
            ['devanagari', 'iast', 'gloss_ru', 'grammar_class', 'top_form'],
            $noteType->fields,
        );

        $deck = SrsDeck::where('slug', 'sanskrit-roots-frequency')->firstOrFail();
        $this->assertSame('system', $deck->visibility);
        $this->assertSame($noteType->id, $deck->note_type_id);
        $this->assertStringContainsString('DCS', $deck->description);

        $this->assertSame(10, SrsCard::count());

        // Card insertion order (id order) must match the fixture's rank order —
        // srs_cards has no sort_rank column, so id order IS the frequency-order signal
        // consumed by ReviewService::queueFor()'s `orderBy('id')` new-card query.
        $cards = $deck->cards()->orderBy('id')->get();
        $this->assertSame(range(1, 10), $cards->pluck('fields.rank')->all());
        $this->assertSame('kṛ', $cards->first()->fields['dcs_lemma']);
        $this->assertSame('कृ', $cards->first()->fields['devanagari']);
        $this->assertNotSame('', $cards->first()->fields['gloss_ru']);
    }

    public function test_is_idempotent_on_rerun_and_updates_in_place(): void
    {
        $this->makeSeeder()->run();
        $this->makeSeeder()->run();

        $this->assertSame(1, SrsNoteType::where('key', 'sanskrit_root')->count());
        $this->assertSame(1, SrsDeck::where('slug', 'sanskrit-roots-frequency')->count());
        $this->assertSame(10, SrsCard::count());
    }

    public function test_missing_fixture_fails_cleanly(): void
    {
        $this->expectException(RuntimeException::class);

        (new SrsRootFrequencyDeckSeeder(sys_get_temp_dir().'/does-not-exist.tsv', null))->run();
    }

    public function test_a_flagged_account_can_review_one_card_end_to_end(): void
    {
        $this->makeSeeder()->run();

        $user = User::factory()->create();
        $deck = SrsDeck::where('slug', 'sanskrit-roots-frequency')->firstOrFail();

        $service = app(ReviewService::class);
        $queue = $service->queueFor($user, $deck);

        $this->assertCount(min(10, (int) config('srs.new_per_day', 20)), $queue);

        $card = $queue->first();
        $this->assertSame('kṛ', $card->fields['dcs_lemma']);

        $state = $service->grade($user, $card, Rating::Good);

        $this->assertNotNull($state->due);
        $this->assertSame(1, $state->reps);
    }
}
