<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\CorpusSandhiController;
use Tests\TestCase;

/**
 * H4718 (census A13) — /reading/sandhi renders the frozen kosha corpus-sandhi layer
 * behind `features.kosha_reader` (default OFF).
 */
class CorpusSandhiPageTest extends TestCase
{
    /** @return array<string, mixed> */
    private function layer(): array
    {
        return json_decode(
            (string) file_get_contents(resource_path(CorpusSandhiController::LAYER)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    public function test_flag_off_route_404s(): void
    {
        config(['features.kosha_reader' => false]);

        $this->get('/reading/sandhi')->assertNotFound();
    }

    public function test_flag_on_renders_top_rules_and_coverage_counts(): void
    {
        config(['features.kosha_reader' => true]);

        $res = $this->get('/reading/sandhi')->assertOk();
        $res->assertSee('Сандхи по частоте', false);
        $res->assertSee('a a → ā', false);          // rank 1
        $res->assertSee('ḥ c → ś c', false);        // rank 8
        $res->assertSee('ajara+amara→ajarāmaravat', false);
        $res->assertSee('707 936', false);          // corpus sandhi events
        $res->assertSee('CC BY-SA 4.0', false);
    }

    /** 10-rule sample: the head of the ranking is the kosha order, with consistent shares. */
    public function test_layer_head_matches_kosha_ranking(): void
    {
        $layer = $this->layer();
        $rules = $layer['rules'];

        $this->assertSame([
            'a a → ā', 'm p → ṃ p', 'm s → ṃ s', 'm v → ṃ v', 'm t → ṃ t',
            'ḥ t → s t', 'm c → ṃ c', 'ḥ c → ś c', 'm k → ṃ k', 'a ā → ā',
        ], array_column(array_slice($rules, 0, 10), 'rule'));

        $events = $layer['stats']['events'];
        $cum = 0;
        foreach ($rules as $i => $rule) {
            $this->assertSame($i + 1, $rule['rank']);
            if ($i > 0) {
                $this->assertLessThanOrEqual($rules[$i - 1]['count'], $rule['count']);
            }
            $cum += $rule['count'];
            $this->assertEqualsWithDelta(100 * $cum / $events, $rule['cum_pct'], 0.01);
        }
        $this->assertGreaterThanOrEqual(90, end($rules)['cum_pct']);
        $this->assertSame(count($rules), $layer['stats']['rules_baked']);
    }

    public function test_bands_partition_rules_at_coverage_cutoffs(): void
    {
        $layer = $this->layer();
        $bands = CorpusSandhiController::bands($layer['rules']);

        $this->assertCount(3, $bands);
        $this->assertCount($layer['stats']['rules_for_50_pct'], $bands[0]['rules']);
        $this->assertSame(
            $layer['stats']['rules_for_80_pct'],
            count($bands[0]['rules']) + count($bands[1]['rules']),
        );
        $this->assertSame(
            count($layer['rules']),
            array_sum(array_map(fn ($b) => count($b['rules']), $bands)),
        );
    }
}
