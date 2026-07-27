<?php

declare(strict_types=1);

namespace Tests\Feature\Games;

use App\Models\GameEvent;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use App\Services\Games\GamesOnboardingImporter;
use Database\Seeders\SrsRootFrequencyDeckSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H1680 — Wave 2 SRS onboarding. Reuses the SAME small sample fixtures as
 * SrsRootFrequencyDeckSeederTest / ImportKocherginaLesson1SrsDeckTest to
 * stand up the canonical decks GamesOnboardingImporter copies cards from.
 */
class GamesOnboardingImporterTest extends TestCase
{
    use RefreshDatabase;

    private function importer(): GamesOnboardingImporter
    {
        return app(GamesOnboardingImporter::class);
    }

    private function seedRootsDeck(): void
    {
        $fixture = base_path('tests/fixtures/srs_roots_frequency/sample.tsv');
        (new SrsRootFrequencyDeckSeeder($fixture, null))->run();
    }

    private function seedKocherginaDeck(): void
    {
        Artisan::call('srs:import-kochergina-lesson1', [
            '--path' => base_path('tests/fixtures/srs_kochergina_lesson1/sample.csv'),
        ]);
    }

    private function completeEvent(User $user, string $drill, string $band): void
    {
        GameEvent::create([
            'anon_id' => 'anon-'.$user->id,
            'user_id' => $user->id,
            'drill' => $drill,
            'band' => $band,
            'event' => GameEvent::COMPLETE,
            'authenticated' => true,
            'created_at' => now(),
        ]);
    }

    public function test_user_with_no_play_history_imports_nothing(): void
    {
        $user = User::factory()->create();

        $imported = $this->importer()->importForUser($user);

        $this->assertSame(0, $imported);
        $this->assertNull(SrsDeck::where('user_id', $user->id)->where('slug', 'onboarding-from-games')->first());
    }

    public function test_completing_an_unmapped_pack_imports_nothing(): void
    {
        $user = User::factory()->create();
        // sort/vowel-length is phonology drilling, not vocabulary — no PACKS mapping.
        $this->completeEvent($user, 'sort', 'vowel-length');

        $imported = $this->importer()->importForUser($user);

        $this->assertSame(0, $imported);
        $this->assertDatabaseCount('srs_decks', 0);
    }

    public function test_imports_cards_from_the_canonical_decks_for_completed_packs(): void
    {
        $this->seedRootsDeck(); // 10 sample cards
        $this->seedKocherginaDeck(); // 5 sample cards
        $user = User::factory()->create();
        $this->completeEvent($user, 'roots', 'top-25');
        $this->completeEvent($user, 'match', 'kochergina-l1');

        $imported = $this->importer()->importForUser($user);

        $this->assertSame(15, $imported); // 10 + 5, both under the cap

        $deck = SrsDeck::where('user_id', $user->id)->where('slug', 'onboarding-from-games')->firstOrFail();
        $this->assertSame('private', $deck->visibility);
        $this->assertSame(15, $deck->cards()->count());

        $noteType = SrsNoteType::where('key', 'games_onboarding')->firstOrFail();
        $this->assertSame($noteType->id, $deck->note_type_id);

        // Cards carry a normalised field shape regardless of source deck's own convention.
        $card = $deck->cards()->first();
        $this->assertArrayHasKey('iast', $card->fields);
        $this->assertArrayHasKey('translation', $card->fields);
        $this->assertNotSame('', $card->fields['translation']);
    }

    public function test_import_never_exceeds_the_20_card_cap(): void
    {
        // Synthetic 30-card system deck sharing the real 'sanskrit-roots-frequency'
        // slug the PACKS map already points 'roots/top-25' at.
        $noteType = SrsNoteType::create([
            'key' => 'sanskrit_root',
            'name' => 'Санскрит — корень (тест)',
            'language' => 'sa',
            'fields' => ['devanagari', 'iast', 'gloss_ru'],
        ]);
        $sourceDeck = SrsDeck::create([
            'slug' => 'sanskrit-roots-frequency',
            'user_id' => null,
            'visibility' => 'system',
            'language' => 'sa',
            'name' => 'Roots (test fixture)',
            'note_type_id' => $noteType->id,
        ]);
        for ($i = 1; $i <= 30; $i++) {
            SrsCard::create([
                'deck_id' => $sourceDeck->id,
                'direction' => 'front_back',
                'fields' => ['iast' => "root{$i}", 'translation_ru' => "gloss {$i}"],
            ]);
        }

        $user = User::factory()->create();
        $this->completeEvent($user, 'roots', 'top-25');

        $imported = $this->importer()->importForUser($user);

        $this->assertSame(20, $imported);
        $deck = SrsDeck::where('user_id', $user->id)->where('slug', 'onboarding-from-games')->firstOrFail();
        $this->assertSame(20, $deck->cards()->count());
    }

    public function test_second_import_call_is_idempotent(): void
    {
        $this->seedRootsDeck();
        $user = User::factory()->create();
        $this->completeEvent($user, 'roots', 'top-25');

        $first = $this->importer()->importForUser($user);
        $second = $this->importer()->importForUser($user);

        $this->assertSame(10, $first);
        $this->assertSame(0, $second);
        $deck = SrsDeck::where('user_id', $user->id)->where('slug', 'onboarding-from-games')->firstOrFail();
        $this->assertSame(10, $deck->cards()->count());
    }

    public function test_merge_guest_events_backfills_prior_anonymous_rows(): void
    {
        $user = User::factory()->create();
        GameEvent::create([
            'anon_id' => 'guestXYZ', 'drill' => 'roots', 'band' => 'top-25',
            'event' => GameEvent::COMPLETE, 'authenticated' => false, 'created_at' => now()->subMinutes(5),
        ]);

        $this->importer()->mergeGuestEvents($user, 'guestXYZ');

        $this->assertSame($user->id, GameEvent::where('anon_id', 'guestXYZ')->first()->user_id);
    }

    public function test_merge_guest_events_is_a_no_op_without_an_anon_id(): void
    {
        $user = User::factory()->create();
        GameEvent::create([
            'anon_id' => 'guestABC', 'drill' => 'roots', 'band' => 'top-25',
            'event' => GameEvent::COMPLETE, 'authenticated' => false, 'created_at' => now(),
        ]);

        $this->importer()->mergeGuestEvents($user, null);

        $this->assertNull(GameEvent::where('anon_id', 'guestABC')->first()->user_id);
    }

    public function test_merge_does_not_touch_another_users_already_linked_rows(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        GameEvent::create([
            'anon_id' => 'shared', 'user_id' => $owner->id, 'drill' => 'roots', 'band' => 'top-25',
            'event' => GameEvent::COMPLETE, 'authenticated' => true, 'created_at' => now(),
        ]);

        $this->importer()->mergeGuestEvents($intruder, 'shared');

        $this->assertSame($owner->id, GameEvent::where('anon_id', 'shared')->first()->user_id);
    }

    public function test_login_first_time_imports_and_flashes_a_toast(): void
    {
        $this->seedKocherginaDeck(); // 5 sample cards
        $user = User::factory()->create(['password' => bcrypt('secret12345')]);
        // Anonymous pre-registration play, same browser (same anon_id the
        // login form will now submit) — this is what the merge step links.
        GameEvent::create([
            'anon_id' => 'loginanon1', 'drill' => 'match', 'band' => 'kochergina-l1',
            'event' => GameEvent::COMPLETE, 'authenticated' => false, 'created_at' => now()->subMinutes(3),
        ]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'secret12345',
            'anon_id' => 'loginanon1',
        ]);

        $response->assertRedirect(route('student.dashboard'));
        $response->assertSessionHas('games_onboarding_status');

        $deck = SrsDeck::where('user_id', $user->id)->where('slug', 'onboarding-from-games')->first();
        $this->assertNotNull($deck);
        $this->assertSame(5, $deck->cards()->count());
        $this->assertSame($user->id, GameEvent::where('anon_id', 'loginanon1')->first()->user_id);
    }

    public function test_cabinet_skill_drills_strip_hidden_by_default(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertDontSee('Короткие тренажёры');
    }

    public function test_cabinet_skill_drills_strip_visible_when_flagged_on(): void
    {
        config(['features.games_cabinet_skill_drills' => true]);
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('student.dashboard'))
            ->assertOk()
            ->assertSee('Короткие тренажёры');
    }
}
