<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GameEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H1680 — Wave 2: the `item_seen` event + sanitized payload, the ingestion
 * side of the onboarding-from-games SRS import (see SrsOnboardingFromGamesTest
 * for the consumption side). Same privacy contract as H1360/H1678: no PII,
 * only the existing anon_id identifies the browser.
 */
class GamesItemSeenTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/games/event';

    /** @test */
    public function item_seen_stores_the_sanitized_payload(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon0003',
            'drill' => 'roots',
            'band' => 'top-25',
            'event' => 'item_seen',
            'payload' => ['items' => [
                ['iast' => 'gam', 'ru' => 'идти'],
                ['iast' => 'kar', 'ru' => 'делать'],
            ]],
        ])->assertNoContent();

        $row = GameEvent::first();
        $this->assertSame('item_seen', $row->event);
        $this->assertSame([
            'items' => [
                ['iast' => 'gam', 'ru' => 'идти'],
                ['iast' => 'kar', 'ru' => 'делать'],
            ],
        ], $row->payload);
    }

    /** @test */
    public function item_seen_payload_is_capped_at_20_items(): void
    {
        $items = [];
        for ($i = 0; $i < 30; $i++) {
            $items[] = ['iast' => "root{$i}", 'ru' => "ru{$i}"];
        }

        $this->postJson(self::URL, [
            'anon_id' => 'anon0004', 'drill' => 'roots', 'event' => 'item_seen',
            'payload' => ['items' => $items],
        ])->assertNoContent();

        $this->assertCount(20, GameEvent::first()->payload['items']);
    }

    /** @test */
    public function items_missing_iast_are_dropped(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon0005', 'drill' => 'roots', 'event' => 'item_seen',
            'payload' => ['items' => [['ru' => 'без иаст'], ['iast' => 'gam', 'ru' => 'идти']]],
        ])->assertNoContent();

        $this->assertSame([['iast' => 'gam', 'ru' => 'идти']], GameEvent::first()->payload['items']);
    }

    /** @test */
    public function non_item_seen_events_never_get_a_payload(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon0006', 'drill' => 'roots', 'event' => 'start',
            'payload' => ['items' => [['iast' => 'gam', 'ru' => 'идти']]],
        ])->assertNoContent();

        $this->assertNull(GameEvent::first()->payload);
    }

    /** @test */
    public function all_junk_items_leave_payload_null(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon0007', 'drill' => 'roots', 'event' => 'item_seen',
            'payload' => ['items' => [['ru' => 'no iast here']]],
        ])->assertNoContent();

        $this->assertNull(GameEvent::first()->payload);
    }
}
