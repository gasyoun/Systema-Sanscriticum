<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GameEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H4692 — the `item_result` event: per-pair difficulty rows from the match
 * engine (ms to first link + wrong-check count), plus the GameEvent::difficulty()
 * aggregate behind games:difficulty. Same privacy contract as H1360/H1680 —
 * curriculum texts and integers only; anon_id / server-stamped user_id as before.
 */
class GamesItemResultTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/games/event';

    /** @test */
    public function item_result_stores_the_sanitized_payload(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon2001',
            'drill' => 'ligatures',
            'band' => 'top-10',
            'event' => 'item_result',
            'payload' => [
                'hints' => 1,
                'items' => [
                    ['l' => 'क्', 'r' => 'ka', 'ms' => 4200, 'wrong' => 1],
                    ['l' => 'त्र', 'r' => 'tra', 'ms' => 900, 'wrong' => 0],
                ],
            ],
        ])->assertNoContent();

        $row = GameEvent::first();
        $this->assertSame('item_result', $row->event);
        $this->assertSame([
            'hints' => 1,
            'items' => [
                ['l' => 'क्', 'r' => 'ka', 'ms' => 4200, 'wrong' => 1],
                ['l' => 'त्र', 'r' => 'tra', 'ms' => 900, 'wrong' => 0],
            ],
        ], $row->payload);
    }

    /** @test */
    public function item_result_is_capped_at_40_items(): void
    {
        $items = [];
        for ($i = 0; $i < 50; $i++) {
            $items[] = ['l' => "left{$i}", 'r' => "right{$i}", 'ms' => 100, 'wrong' => 0];
        }

        $this->postJson(self::URL, [
            'anon_id' => 'anon2002', 'drill' => 'match', 'event' => 'item_result',
            'payload' => ['hints' => 0, 'items' => $items],
        ])->assertNoContent();

        $this->assertCount(40, GameEvent::first()->payload['items']);
    }

    /** @test */
    public function item_result_clamps_ms_wrong_and_hints(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon2003', 'drill' => 'match', 'event' => 'item_result',
            'payload' => [
                'hints' => 'yes',
                'items' => [
                    ['l' => 'a', 'r' => 'b', 'ms' => 99_999_999, 'wrong' => 999],
                    ['l' => 'c', 'r' => 'd', 'ms' => -5, 'wrong' => -3],
                ],
            ],
        ])->assertNoContent();

        $payload = GameEvent::first()->payload;
        $this->assertSame(0, $payload['hints']);
        $this->assertSame(3_600_000, $payload['items'][0]['ms']);
        $this->assertSame(50, $payload['items'][0]['wrong']);
        $this->assertSame(0, $payload['items'][1]['ms']);
        $this->assertSame(0, $payload['items'][1]['wrong']);
    }

    /** @test */
    public function items_missing_either_side_are_dropped(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon2004', 'drill' => 'match', 'event' => 'item_result',
            'payload' => [
                'hints' => 0,
                'items' => [
                    ['l' => 'без правой', 'ms' => 100, 'wrong' => 0],
                    ['l' => 'gam', 'r' => 'идти', 'ms' => 100, 'wrong' => 0],
                ],
            ],
        ])->assertNoContent();

        $this->assertSame(
            [['l' => 'gam', 'r' => 'идти', 'ms' => 100, 'wrong' => 0]],
            GameEvent::first()->payload['items'],
        );
    }

    /** @test */
    public function all_junk_items_leave_payload_null(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon2005', 'drill' => 'match', 'event' => 'item_result',
            'payload' => ['hints' => 0, 'items' => [['r' => 'no left here']]],
        ])->assertNoContent();

        $this->assertNull(GameEvent::first()->payload);
    }

    /** @test */
    public function difficulty_aggregates_median_avg_and_wrong_rate(): void
    {
        $post = fn (string $anon, int $ms, int $wrong) => $this->postJson(self::URL, [
            'anon_id' => $anon, 'drill' => 'ligatures', 'band' => 'top-10',
            'event' => 'item_result',
            'payload' => ['hints' => 0, 'items' => [
                ['l' => 'क्', 'r' => 'ka', 'ms' => $ms, 'wrong' => $wrong],
            ]],
        ])->assertNoContent();

        $post('anon2006', 1000, 0);
        $post('anon2007', 3000, 2);

        $rows = GameEvent::difficulty(now()->subDay());

        $this->assertCount(1, $rows);
        $row = $rows[0];
        $this->assertSame('ligatures', $row['drill']);
        $this->assertSame('top-10', $row['band']);
        $this->assertSame('ka', $row['item']);
        $this->assertSame(2, $row['rounds']);
        $this->assertSame(2000, $row['median_ms']);
        $this->assertSame(2000, $row['avg_ms']);
        $this->assertSame(50.0, $row['wrong_rate']);
    }

    /** @test */
    public function difficulty_sorts_hardest_first(): void
    {
        $post = fn (string $anon, string $r, int $ms, int $wrong) => $this->postJson(self::URL, [
            'anon_id' => $anon, 'drill' => 'ligatures', 'band' => 'top-10',
            'event' => 'item_result',
            'payload' => ['hints' => 0, 'items' => [
                ['l' => 'क्', 'r' => $r, 'ms' => $ms, 'wrong' => $wrong],
            ]],
        ])->assertNoContent();

        // «tz» — ошибки и долгое время; «sy» — быстрый чистый раунд.
        $post('anon2008', 'tz', 5000, 3);
        $post('anon2009', 'sy', 500, 0);

        $rows = GameEvent::difficulty(now()->subDay());

        $this->assertSame(['tz', 'sy'], array_column($rows, 'item'));
    }
}
