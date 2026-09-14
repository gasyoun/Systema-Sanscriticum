<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GameEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * H4692 — the `item_result` event (per-pair difficulty of a completed
 * match round) + the games:difficulty report. Same privacy contract as
 * H1360/H1680: no PII, only the existing anon_id identifies the browser.
 */
class GamesItemResultTelemetryTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/games/event';

    /** @test */
    public function item_result_stores_the_sanitized_payload(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon0901',
            'drill' => 'match',
            'band' => 'verb-roots',
            'event' => 'item_result',
            'payload' => [
                'hints' => 2,
                'items' => [
                    ['l' => 'गच्छति', 'r' => 'идти', 'ms' => 4200, 'wrong' => 1],
                    ['l' => 'करोति', 'r' => 'делать', 'ms' => -50, 'wrong' => 'junk'],
                ],
            ],
        ])->assertNoContent();

        $row = GameEvent::first();
        $this->assertSame('item_result', $row->event);
        $this->assertSame([
            'hints' => 2,
            'items' => [
                ['l' => 'गच्छति', 'r' => 'идти', 'ms' => 4200, 'wrong' => 1],
                // не-число ms/wrong -> 0, отрицательное -> 0
                ['l' => 'करोति', 'r' => 'делать', 'ms' => 0, 'wrong' => 0],
            ],
        ], $row->payload);
    }

    /** @test */
    public function item_result_items_are_capped_at_50(): void
    {
        $items = [];
        for ($i = 0; $i < 60; $i++) {
            $items[] = ['l' => "l{$i}", 'r' => "r{$i}", 'ms' => 1000 + $i, 'wrong' => 0];
        }

        $this->postJson(self::URL, [
            'anon_id' => 'anon0902', 'drill' => 'match', 'event' => 'item_result',
            'payload' => ['hints' => 0, 'items' => $items],
        ])->assertNoContent();

        $this->assertCount(50, GameEvent::first()->payload['items']);
    }

    /** @test */
    public function item_result_without_valid_items_leaves_payload_null(): void
    {
        $this->postJson(self::URL, [
            'anon_id' => 'anon0903', 'drill' => 'match', 'event' => 'item_result',
            'payload' => ['hints' => 0, 'items' => [['r' => 'нет левой стороны']]],
        ])->assertNoContent();

        $this->assertNull(GameEvent::first()->payload);
    }

    /** @test */
    public function difficulty_report_prints_median_mean_and_wrong_rate(): void
    {
        // Два раунда по одной паре: ms 1000 и 3000 -> медиана 2000, среднее 2000;
        // в одном из двух наблюдений wrong=1 -> wrong-rate 50%.
        foreach ([['ms' => 1000, 'wrong' => 0], ['ms' => 3000, 'wrong' => 1]] as $item) {
            GameEvent::create([
                'anon_id' => 'anon0904',
                'drill' => 'match',
                'band' => 'verb-roots',
                'event' => GameEvent::ITEM_RESULT,
                'payload' => ['hints' => 0, 'items' => [['l' => 'गच्छति', 'r' => 'идти'] + $item]],
                'authenticated' => false,
                'created_at' => now(),
            ]);
        }

        $exit = Artisan::call('games:difficulty', ['--days' => '30']);
        $this->assertSame(0, $exit);

        $out = Artisan::output();
        $this->assertStringContainsString('गच्छति → идти', $out);
        $this->assertStringContainsString('2000', $out); // медиана (и среднее) из ms 1000/3000
        $this->assertStringContainsString('50%', $out); // 1 неверная проверка из 2 наблюдений
    }

    /** @test */
    public function difficulty_report_is_honest_about_an_empty_window(): void
    {
        $exit = Artisan::call('games:difficulty', ['--days' => '7']);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Пока ни одного item_result', Artisan::output());
    }
}
