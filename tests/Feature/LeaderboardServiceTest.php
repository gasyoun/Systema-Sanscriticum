<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LilaScoreEvent;
use App\Models\MemriseLeaderboardImport;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\SrsReviewLog;
use App\Models\User;
use App\Services\Leaderboard\LeaderboardService;
use App\Support\PranaLeaderboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H2054 — multi-board gamification: SRS / lila / Memrise import / config disable.
 */
class LeaderboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): LeaderboardService
    {
        return app(LeaderboardService::class);
    }

    private function deck(string $slug): SrsDeck
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'lb_test'],
            ['name' => 'LB test', 'language' => 'sa', 'fields' => ['front', 'back']],
        );

        return SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => $slug,
            'slug' => $slug,
            'language' => 'sa',
            'visibility' => 'system',
        ]);
    }

    private function review(User $user, SrsDeck $deck, int $rating, ?Carbon $at = null): void
    {
        $card = SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => ['front' => 'a', 'back' => 'b'],
        ]);

        SrsReviewLog::create([
            'user_id' => $user->id,
            'card_id' => $card->id,
            'deck_id' => $deck->id,
            'rating' => $rating,
            'state' => 2,
            'reviewed_at' => $at ?? now(),
        ]);
    }

    /** @test */
    public function srs_prodlenka_board_scores_only_matching_deck_prefix(): void
    {
        $a = User::factory()->create(['is_admin' => false, 'name' => 'Алиса Корень']);
        $b = User::factory()->create(['is_admin' => false, 'name' => 'Борис Лист']);

        $prod = $this->deck('memrise-6679375-level-1');
        $other = $this->deck('memrise-6502608-level-1');

        // A: 4+3 = 7 on Prodlenka; B: 4 on other course only
        $this->review($a, $prod, 4);
        $this->review($a, $prod, 3);
        $this->review($b, $other, 4);
        $this->review($b, $prod, 2); // 2 pts on Prodlenka

        $rows = $this->service()->rows('srs_memrise_6679375', PranaLeaderboard::PERIOD_ALL, 10);
        $this->assertSame([7, 2], $rows->pluck('score')->all());
        $this->assertSame('Алиса К.', $rows->first()['display']);
    }

    /** @test */
    public function lila_board_sums_authenticated_points_by_drill(): void
    {
        $u = User::factory()->create(['is_admin' => false, 'name' => 'Вера Сорт']);
        LilaScoreEvent::create([
            'user_id' => $u->id,
            'drill' => 'sort',
            'band' => 'a1',
            'points' => 15,
            'event' => 'complete',
            'created_at' => now(),
        ]);
        LilaScoreEvent::create([
            'user_id' => $u->id,
            'drill' => 'match',
            'band' => null,
            'points' => 99,
            'event' => 'complete',
            'created_at' => now(),
        ]);

        $sort = $this->service()->rows('lila_sort', 'all', 10);
        $this->assertCount(1, $sort);
        $this->assertSame(15, $sort->first()['score']);

        $all = $this->service()->rows('lila_all', 'all', 10);
        $this->assertSame(114, $all->first()['score']);
    }

    /** @test */
    public function memrise_import_shows_unlinked_usernames_and_links_via_username(): void
    {
        MemriseLeaderboardImport::create([
            'course_id' => '6679375',
            'username' => 'SanskritHero',
            'points' => 5000,
            'period' => 'all',
            'rank' => 1,
            'imported_at' => now(),
        ]);

        $rows = $this->service()->rows('memrise_import_6679375', 'all', 10);
        $this->assertCount(1, $rows);
        $this->assertSame('SanskritHero', $rows->first()['display']);
        $this->assertSame(5000, $rows->first()['score']);
        $this->assertStringContainsString('не привязан', $rows->first()['rank']);

        $user = User::factory()->create([
            'is_admin' => false,
            'name' => 'Герой Санскрита',
            'memrise_username' => 'SanskritHero',
        ]);
        Artisan::call('leaderboard:import-memrise', ['--link-only' => true]);

        $rows2 = $this->service()->rows('memrise_import_6679375', 'all', 10, $user->id);
        $this->assertTrue($rows2->first()['is_me']);
        $this->assertSame(5000, $rows2->first()['score']);
        $this->assertStringNotContainsString('не привязан', $rows2->first()['rank']);
    }

    /** @test */
    public function import_command_upserts_csv(): void
    {
        $path = storage_path('app/testing_memrise_lb.csv');
        file_put_contents($path, "course_id,username,points,period,rank\n6502608,Alice,100,all,1\n6502608,Bob,50,all,2\n");

        $this->artisan('leaderboard:import-memrise', ['path' => $path])
            ->assertSuccessful();

        $this->assertDatabaseHas('memrise_leaderboard_imports', [
            'course_id' => '6502608',
            'username' => 'Alice',
            'points' => 100,
        ]);

        // Upsert updates points
        file_put_contents($path, "course_id,username,points,period,rank\n6502608,Alice,999,all,1\n");
        $this->artisan('leaderboard:import-memrise', ['path' => $path])->assertSuccessful();
        $this->assertDatabaseHas('memrise_leaderboard_imports', [
            'username' => 'Alice',
            'points' => 999,
        ]);

        @unlink($path);
    }

    /** @test */
    public function disabled_board_returns_empty(): void
    {
        config(['leaderboards.boards.srs_all.enabled' => false]);
        $u = User::factory()->create(['is_admin' => false]);
        $deck = $this->deck('memrise-6679375-level-1');
        $this->review($u, $deck, 4);

        $this->assertTrue($this->service()->rows('srs_all', 'all', 10)->isEmpty());
    }

    /** @test */
    public function all_enabled_boards_skips_disabled_and_includes_period_keys(): void
    {
        config([
            'leaderboards.boards' => [
                'prana' => ['enabled' => true, 'label' => 'Прана', 'source' => 'prana'],
                'srs_all' => ['enabled' => false, 'label' => 'SRS', 'source' => 'srs'],
            ],
        ]);

        $boards = $this->service()->allEnabledBoards(5);
        $this->assertCount(1, $boards);
        $this->assertSame('prana', $boards[0]['key']);
        $this->assertArrayHasKey('week', $boards[0]['periods']);
        $this->assertArrayHasKey('month', $boards[0]['periods']);
        $this->assertArrayHasKey('all', $boards[0]['periods']);
    }

    /** @test */
    public function game_telemetry_complete_writes_server_derived_points_ignoring_client_score(): void
    {
        // H3315: очки берутся из серверной таблицы по типу события (complete = 10),
        // payload.score клиента игнорируется — раньше forged score накручивал борд.
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->postJson('/api/games/event', [
                'event' => 'complete',
                'drill' => 'cloze',
                'band' => 'b1',
                'payload' => ['score' => 999999],
            ])
            ->assertNoContent();

        $this->assertDatabaseHas('lila_score_events', [
            'user_id' => $user->id,
            'drill' => 'cloze',
            'points' => 10,
        ]);
    }
}
