<?php

declare(strict_types=1);

namespace Tests\Feature\Srs;

use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use App\Models\User;
use App\Services\Srs\Fsrs;
use App\Services\Srs\Rating;
use App\Services\Srs\ReviewService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H447 — per-trainer stats dashboard over srs_review_logs: progress bar,
 * correct/incorrect ratio, error list. Same 404-when-disabled gate as the
 * review route.
 */
class SrsStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        // H1145: `srs.enabled` now defaults to false (R-6). routes/web.php
        // registers `student.srs.stats` inside `if (config('srs.enabled'))`
        // at application boot — a config() override made after parent::setUp()
        // is too late for that, since the route is either registered or not
        // by the time this method's own body runs. Set the env var before
        // booting the app so the route actually gets registered.
        putenv('SRS_ENABLED=true');
        $_ENV['SRS_ENABLED'] = 'true';
        $_SERVER['SRS_ENABLED'] = 'true';

        parent::setUp();
        config(['srs.enabled' => true]);
    }

    protected function tearDown(): void
    {
        putenv('SRS_ENABLED');
        unset($_ENV['SRS_ENABLED'], $_SERVER['SRS_ENABLED']);

        parent::tearDown();
    }

    private function makeDeck(int $cardCount): SrsDeck
    {
        $noteType = SrsNoteType::create([
            'key' => 'sanskrit_basic',
            'name' => 'Санскрит',
            'language' => 'sa',
            'fields' => ['devanagari', 'translation'],
        ]);

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => 'Test deck',
            'language' => 'sa',
            'visibility' => 'system',
        ]);

        for ($i = 0; $i < $cardCount; $i++) {
            SrsCard::create([
                'deck_id' => $deck->id,
                'direction' => 'front_back',
                'fields' => ['devanagari' => "पद{$i}", 'translation' => "meaning {$i}"],
            ]);
        }

        return $deck;
    }

    public function test_stats_page_shows_progress_and_error_list(): void
    {
        $user = User::factory()->create();
        $deck = $this->makeDeck(2);
        $cards = $deck->cards()->get();

        $service = new ReviewService(new Fsrs(enableFuzzing: false));
        $now = new DateTimeImmutable('2026-07-07 10:00:00');
        $service->grade($user, $cards[0], Rating::Good, null, $now);
        $service->grade($user, $cards[1], Rating::Again, null, $now);

        $response = $this->actingAs($user)->get(route('student.srs.stats'));

        $response->assertOk();
        $response->assertSee($deck->name);
        $response->assertSee('meaning 1'); // the wrong answer surfaces in the error list
    }

    public function test_stats_route_aborts_when_flag_disabled(): void
    {
        config(['srs.enabled' => false]);
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/dvaram/srs/stats');

        $response->assertNotFound();
    }
}
