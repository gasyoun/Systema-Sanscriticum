<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Content\SpanRanker;
use Tests\TestCase;

class SpanRankerTest extends TestCase
{
    /**
     * @return list<array{start_seconds:int,end_seconds:int,title:string}>
     */
    private function spans(): array
    {
        // 8 spans across a ~800s lesson, varied duration/position/title richness.
        return [
            ['start_seconds' => 0, 'end_seconds' => 20, 'title' => 'Тема — фрагмент 1: вступление'],
            ['start_seconds' => 100, 'end_seconds' => 190, 'title' => 'Тема — фрагмент 2: разбор падежей подробно и с примерами'],
            ['start_seconds' => 200, 'end_seconds' => 260, 'title' => 'Тема — фрагмент 3: глаголы'],
            ['start_seconds' => 300, 'end_seconds' => 380, 'title' => 'Тема — фрагмент 4: настоящее время спряжения глаголов подробно'],
            ['start_seconds' => 400, 'end_seconds' => 460, 'title' => 'Тема — фрагмент 5: прошедшее время'],
            ['start_seconds' => 500, 'end_seconds' => 560, 'title' => 'Тема — фрагмент 6: будущее время глаголов'],
            ['start_seconds' => 600, 'end_seconds' => 610, 'title' => 'Тема — фрагмент 7: короткая вставка'],
            ['start_seconds' => 700, 'end_seconds' => 800, 'title' => 'Тема — фрагмент 8: итоговое резюме лекции целиком'],
        ];
    }

    public function test_returns_at_most_n(): void
    {
        $ranked = SpanRanker::rank($this->spans(), 5);
        $this->assertLessThanOrEqual(5, count($ranked));
        $this->assertGreaterThan(0, count($ranked));
    }

    public function test_default_n_is_five_from_config(): void
    {
        config(['content.clip_rank_n' => 3]);
        $ranked = SpanRanker::rank($this->spans());
        $this->assertCount(3, $ranked);
    }

    public function test_result_stays_chronologically_ordered(): void
    {
        $ranked = SpanRanker::rank($this->spans(), 5);

        $starts = array_column($ranked, 'start_seconds');
        $sorted = $starts;
        sort($sorted);
        $this->assertSame($sorted, $starts);
    }

    public function test_does_not_recompute_boundaries(): void
    {
        $spans = $this->spans();
        $ranked = SpanRanker::rank($spans, 5);

        foreach ($ranked as $span) {
            $match = array_values(array_filter(
                $spans,
                fn (array $s): bool => $s['start_seconds'] === $span['start_seconds']
                    && $s['end_seconds'] === $span['end_seconds']
            ));
            $this->assertNotEmpty($match, 'Ranked span boundaries must match an original span exactly.');
        }
    }

    public function test_empty_input_yields_empty_output(): void
    {
        $this->assertSame([], SpanRanker::rank([], 5));
    }

    public function test_n_larger_than_available_returns_all(): void
    {
        $spans = array_slice($this->spans(), 0, 3);
        $ranked = SpanRanker::rank($spans, 10);
        $this->assertCount(3, $ranked);
    }
}
