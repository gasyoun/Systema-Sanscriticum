<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\GameEvent;
use App\Models\SrsDeck;
use App\Models\User;
use App\Services\Srs\SrsOnboardingFromGames;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1680 — Wave 2: register→SRS onboarding from free /lila play.
 * SrsOnboardingFromGames matches ONLY on the existing `anon_id` (never a
 * user_id/guest_id column on game_events, see GamesFunnelTelemetryTest::
 * the_table_stores_no_ip_or_user_agent_by_design) and is behind srs.enabled,
 * OFF by default (R-6).
 */
class SrsOnboardingFromGamesTest extends TestCase
{
    use RefreshDatabase;

    private function seedItemSeen(string $anonId, array $items, ?\DateTimeInterface $at = null): void
    {
        GameEvent::create([
            'anon_id' => $anonId,
            'drill' => 'roots',
            'band' => 'top-25',
            'event' => GameEvent::ITEM_SEEN,
            'payload' => ['items' => $items],
            'authenticated' => false,
            'created_at' => $at ?? now(),
        ]);
    }

    /** @test */
    public function flag_off_is_a_no_op_even_with_play_history(): void
    {
        config(['srs.enabled' => false]);
        $this->seedItemSeen('guest001', [['iast' => 'gam', 'ru' => 'идти']]);
        $user = User::factory()->create();

        $imported = app(SrsOnboardingFromGames::class)->importForUser($user, 'guest001');

        $this->assertSame(0, $imported);
        $this->assertDatabaseCount('srs_decks', 0);
        $this->assertDatabaseCount('srs_cards', 0);
    }

    /** @test */
    public function empty_guest_history_is_a_no_op(): void
    {
        config(['srs.enabled' => true]);
        $user = User::factory()->create();

        $imported = app(SrsOnboardingFromGames::class)->importForUser($user, 'guest-never-played');

        $this->assertSame(0, $imported);
        $this->assertDatabaseCount('srs_decks', 0);
    }

    /** @test */
    public function null_anon_id_is_a_no_op(): void
    {
        config(['srs.enabled' => true]);
        $user = User::factory()->create();

        $imported = app(SrsOnboardingFromGames::class)->importForUser($user, null);

        $this->assertSame(0, $imported);
        $this->assertDatabaseCount('srs_decks', 0);
    }

    /** @test */
    public function import_is_capped_at_20_distinct_lemmas(): void
    {
        config(['srs.enabled' => true]);
        $items = [];
        for ($i = 0; $i < 25; $i++) {
            $items[] = ['iast' => "root{$i}", 'ru' => "перевод{$i}"];
        }
        $this->seedItemSeen('guest002', $items);
        $user = User::factory()->create();

        $imported = app(SrsOnboardingFromGames::class)->importForUser($user, 'guest002');

        $this->assertSame(20, $imported);
        $this->assertDatabaseCount('srs_cards', 20);
    }

    /** @test */
    public function distinct_lemmas_are_deduped_across_events(): void
    {
        config(['srs.enabled' => true]);
        $this->seedItemSeen('guest003', [['iast' => 'gam', 'ru' => 'идти']], now()->subMinute());
        $this->seedItemSeen('guest003', [['iast' => 'gam', 'ru' => 'идти'], ['iast' => 'kar', 'ru' => 'делать']]);
        $user = User::factory()->create();

        $imported = app(SrsOnboardingFromGames::class)->importForUser($user, 'guest003');

        $this->assertSame(2, $imported);
        $this->assertDatabaseCount('srs_cards', 2);
    }

    /** @test */
    public function second_login_creates_no_duplicate_cards_and_subscribes_once(): void
    {
        config(['srs.enabled' => true]);
        $this->seedItemSeen('guest004', [['iast' => 'gam', 'ru' => 'идти'], ['iast' => 'kar', 'ru' => 'делать']]);
        $user = User::factory()->create();
        $service = app(SrsOnboardingFromGames::class);

        $first = $service->importForUser($user, 'guest004');
        $this->assertDatabaseCount('srs_cards', 2);

        $second = $service->importForUser($user, 'guest004');

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('srs_cards', 2); // no duplicates
        $this->assertDatabaseCount('srs_decks', 1); // one system deck, not one per call

        $deck = SrsDeck::where('slug', SrsOnboardingFromGames::DECK_SLUG)->first();
        $this->assertSame(1, $deck->subscribers()->count());
    }

    /** @test */
    public function deck_is_a_system_deck_with_the_stable_slug(): void
    {
        config(['srs.enabled' => true]);
        $this->seedItemSeen('guest005', [['iast' => 'gam', 'ru' => 'идти']]);
        $user = User::factory()->create();

        app(SrsOnboardingFromGames::class)->importForUser($user, 'guest005');

        $deck = SrsDeck::where('slug', 'onboarding-from-games')->first();
        $this->assertNotNull($deck);
        $this->assertNull($deck->user_id);
        $this->assertSame('system', $deck->visibility);
    }

    /** @test */
    public function two_users_sharing_a_lemma_do_not_duplicate_the_card(): void
    {
        config(['srs.enabled' => true]);
        $this->seedItemSeen('guestA', [['iast' => 'gam', 'ru' => 'идти']]);
        $this->seedItemSeen('guestB', [['iast' => 'gam', 'ru' => 'идти']]);
        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $service = app(SrsOnboardingFromGames::class);

        $service->importForUser($userA, 'guestA');
        $service->importForUser($userB, 'guestB');

        $this->assertDatabaseCount('srs_cards', 1);
        $deck = SrsDeck::where('slug', SrsOnboardingFromGames::DECK_SLUG)->first();
        $this->assertSame(2, $deck->subscribers()->count());
    }

    /** @test */
    public function import_endpoint_requires_auth(): void
    {
        $this->postJson('/api/games/srs-onboarding-import', ['anon_id' => 'guest006'])
            ->assertUnauthorized();
    }

    /** @test */
    public function import_endpoint_reports_imported_count_for_the_authenticated_caller(): void
    {
        config(['srs.enabled' => true]);
        $this->seedItemSeen('guest007', [['iast' => 'gam', 'ru' => 'идти']]);
        $this->actingAs(User::factory()->create());

        $this->postJson('/api/games/srs-onboarding-import', ['anon_id' => 'guest007'])
            ->assertOk()
            ->assertJson(['imported' => 1]);
    }
}
