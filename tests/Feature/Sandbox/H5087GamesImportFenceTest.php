<?php

declare(strict_types=1);

namespace Tests\Feature\Sandbox;

use App\Models\DictionaryWord;
use App\Models\GameEvent;
use App\Models\SrsCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5087 regression · games.telemetry.payload.to.shared.system.srs.deck.
 *
 * Same seeding chain as the NV-09 proof, assertions flipped to the fixed
 * invariant: the telemetry intake charset-fences lemma strings (no
 * formula/markup-bearing characters stored), the import accepts only
 * anon_ids the caller's session actually sent, and the shared-surface write
 * re-sanitizes even legacy payload rows.
 */
class H5087GamesImportFenceTest extends TestCase
{
    use RefreshDatabase;

    private const ANON = 'h5087anon';

    private const MARKER = 'h5087marker';

    private const FORMULA = '=HYPERLINK("http://127.0.0.1:9/x","p")';

    protected function setUp(): void
    {
        parent::setUp();
        config(['srs.enabled' => true]);
    }

    /** @test */
    public function intake_strips_formula_and_markup_characters_from_lemmas(): void
    {
        $this->postJson('/api/games/event', [
            'anon_id' => self::ANON,
            'drill' => 'lila',
            'event' => 'item_seen',
            'payload' => ['items' => [
                ['iast' => self::MARKER, 'ru' => 'маркер '.self::FORMULA],
            ]],
        ])->assertSuccessful();

        $row = GameEvent::where('anon_id', self::ANON)->firstOrFail();
        $stored = (string) $row->payload['items'][0]['ru'];

        $this->assertStringNotContainsString('=', $stored, 'formula leader stripped at intake');
        $this->assertStringNotContainsString('"', $stored, 'quote stripped at intake');
        $this->assertStringNotContainsString(':', $stored, 'colon stripped at intake');
        // Inert letters survive.
        $this->assertStringContainsString('маркер', $stored);
        $this->assertStringContainsString('HYPERLINK', $stored, 'letters themselves are kept (inert text)');
    }

    /** @test */
    public function import_rejects_anon_id_never_sent_by_this_browser_session(): void
    {
        // Rows seeded by ANOTHER browser (direct DB write — what a cross-principal
        // caller effectively claims): the import must not publish them.
        GameEvent::create([
            'anon_id' => self::ANON,
            'drill' => 'lila',
            'event' => GameEvent::ITEM_SEEN,
            'payload' => ['items' => [['iast' => self::MARKER, 'ru' => 'маркер']]],
            'authenticated' => false,
            'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->create())
            ->postJson('/api/games/srs-onboarding-import', ['anon_id' => self::ANON])
            ->assertOk()
            ->assertJson(['imported' => 0]);

        $this->assertDatabaseCount('srs_cards', 0);
        $this->assertDatabaseMissing('dictionary_words', ['iast' => self::MARKER]);
    }

    /** @test */
    public function legacy_dirty_payload_is_re_sanitized_at_the_shared_surface_boundary(): void
    {
        // The session legitimately sent this anon_id…
        $this->postJson('/api/games/event', [
            'anon_id' => self::ANON,
            'drill' => 'lila',
            'event' => 'item_seen',
            'payload' => ['items' => [['iast' => self::MARKER, 'ru' => 'x']]],
        ])->assertSuccessful();

        // …but the stored payload row predates the intake fence (legacy data).
        $row = GameEvent::where('anon_id', self::ANON)->firstOrFail();
        $row->payload = ['items' => [['iast' => self::MARKER, 'ru' => 'маркер '.self::FORMULA]]];
        $row->save();

        $this->actingAs(User::factory()->create())
            ->postJson('/api/games/srs-onboarding-import', ['anon_id' => self::ANON])
            ->assertOk()
            ->assertJson(['imported' => 1]);

        $card = SrsCard::query()
            ->where('fields', 'like', '%'.self::MARKER.'%')
            ->firstOrFail();
        $translation = (string) $card->fields['translation_ru'];
        $this->assertStringNotContainsString('=', $translation, 'boundary re-sanitize strips formula characters from legacy rows');

        $word = DictionaryWord::where('iast', self::MARKER)->firstOrFail();
        $this->assertStringNotContainsString('=', (string) $word->translation);
    }
}
