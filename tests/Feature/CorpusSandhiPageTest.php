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

    public function test_flag_on_renders_teaching_order_view(): void
    {
        config(['features.kosha_reader' => true]);

        $res = $this->get('/reading/sandhi')->assertOk();
        $res->assertSee('Порядок изучения', false);
        $res->assertSee('Урок 1', false);
        $res->assertSee('sandhi-curriculum', false);   // curriculum provenance
        $res->assertSee('3ccfd405', false);            // pinned kosha commits
        // lesson 1 = the two highest-priority rules, in curriculum order
        $this->assertGreaterThan(
            strpos($res->getContent(), 'a a → ā'),
            strpos($res->getContent(), 'Порядок изучения'),
        );
    }

    /** Teaching order: every baked frequency rule joins exactly once, curriculum order is total. */
    public function test_layer_teaching_order_joins_every_baked_rule(): void
    {
        $layer = $this->layer();

        $this->assertSame('corpus_sandhi_top_v2', $layer['schema']);
        $order = $layer['curriculum']['teaching_order'];

        $this->assertSame(count($layer['rules']), count($order));
        $ranks = array_column($order, 'rank');
        sort($ranks);
        $this->assertSame(
            range(1, count($layer['rules'])),
            $ranks,
            'teaching_order must reference every frequency rank exactly once',
        );
        $prev = 0;
        foreach ($order as $entry) {
            $this->assertGreaterThan($prev, $entry['curriculum_rank']);
            $prev = $entry['curriculum_rank'];
            $this->assertContains($entry['lesson'], range(1, 10));
        }
    }

    /**
     * 10-rule source-joined sample: the head of kosha sandhi_curriculum.tsv
     * (origin/main 3ccfd4050, blob f65564d5), hand-checked 11-10-2026.
     * Each row pins curriculum rank, lesson, frequency rank and the example
     * the frequency layer already carries — ranks, examples, provenance preserved.
     */
    public function test_layer_teaching_order_sample_matches_kosha_curriculum(): void
    {
        $layer = $this->layer();
        $this->assertSame('sandhi-curriculum', $layer['curriculum']['source']['dataset']);
        $this->assertSame('3ccfd4050c3b68595cb333861183aae6befa6654', $layer['curriculum']['source']['commit']);
        $this->assertSame('f65564d54d4bf7c75ad867cd58b7c57095d70023', $layer['curriculum']['source']['blob']);
        $this->assertSame('CC BY-SA 4.0', $layer['curriculum']['source']['license']);

        $byRule = collect($layer['rules'])->keyBy('rule');
        $head = array_slice($layer['curriculum']['teaching_order'], 0, 10);

        $sample = [ // curriculum_rank => [rule, lesson, frequency_rank, example split]
            1 => ['a a → ā', 1, 1, 'ajara+amara→ajarāmaravat'],
            2 => ['m p → ṃ p', 1, 2, 'udyoginaṃ+puruṣa'],
            3 => ['m s → ṃ s', 2, 3, 'pāṭavaṃ+saṃskṛta'],
            4 => ['m v → ṃ v', 2, 4, 'vinayaṃ+vinayād'],
            5 => ['m t → ṃ t', 2, 5, 'dharmaṃ+tataḥ'],
            6 => ['m c → ṃ c', 3, 7, 'artham→vidyāmarthaṃ+ca'],
            7 => ['m k → ṃ k', 3, 9, 'kiṃ+kariṣyati'],
            8 => ['m m → ṃ m', 3, 11, 'prathitaṃ+manaḥ'],
            9 => ['m n → ṃ n', 3, 12, 'vaicitryaṃ+nīti'],
            10 => ['a ā → ā', 3, 10, 'ākarṇya+ātmanaḥ→ākarṇyātmanaḥ'],
        ];

        foreach ($head as $i => $entry) {
            $expected = $sample[$i + 1];
            $this->assertSame($i + 1, $entry['curriculum_rank']);
            $this->assertSame($expected[0], $entry['rule']);
            $this->assertSame($expected[0], $byRule[$entry['rule']]['rule']);
            $this->assertSame($expected[1], $entry['lesson']);
            $this->assertSame($expected[2], $byRule[$entry['rule']]['rank']);
            $this->assertSame($expected[3], $byRule[$entry['rule']]['example']['split']);
        }
    }

    public function test_teaching_groups_follow_lessons_then_tail(): void
    {
        $layer = $this->layer();
        $groups = CorpusSandhiController::teachingGroups($layer['rules'], $layer['curriculum']);

        $this->assertSame(1, $groups[0]['lesson']);                     // lesson 1 first
        $this->assertSame(1, $groups[0]['rules'][0]['curriculum_rank']);
        $this->assertNull(end($groups)['lesson']);                       // lesson-10 tail last
        $this->assertSame(
            count($layer['rules']),
            array_sum(array_map(fn ($g) => count($g['rules']), $groups)),
        );
        // frequency denominator untouched by the join
        $this->assertSame(707936, $layer['stats']['events']);
    }
}
