<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * H4718 (census A13) — /reading/sandhi: the sandhi rules a reader actually meets,
 * ranked by frequency across 41 DCS texts (kosha `corpus-sandhi`).
 *
 * H6304 adds the teaching-order view: the same rules re-ordered by kosha's
 * `sandhi-curriculum` (priority = frequency × class × environment-generality,
 * MG ruling 14-07-2026). Frequency order, stats and denominator are unchanged.
 *
 * Renders the frozen layer baked by scripts/vendor_corpus_sandhi.py; never
 * hand-edit resources/data/corpus_sandhi/. Same flag as the rest of the kosha
 * reader (`features.kosha_reader`, default OFF → 404).
 */
class CorpusSandhiController extends Controller
{
    public const LAYER = 'data/corpus_sandhi/corpus_sandhi_top.json';

    /** Coverage bands the page groups rules into: «learn these first» → long tail. */
    public const BANDS = [50, 80, 90];

    /** Lessons above this are the curriculum's ungraded tail (lesson 10). */
    public const GRADED_LESSONS = 9;

    public function show(): View
    {
        abort_if(! config('features.kosha_reader', false), 404);

        $layer = json_decode(
            (string) file_get_contents(resource_path(self::LAYER)),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return view('reading.sandhi', [
            'layer' => $layer,
            'bands' => self::bands($layer['rules']),
            'teachingGroups' => self::teachingGroups($layer['rules'], $layer['curriculum']),
        ]);
    }

    /**
     * Split the ranked rules into coverage bands: a rule belongs to the first band
     * whose cutoff its cumulative share reaches, so band 1 is exactly the rules
     * needed to read half of all corpus sandhi.
     *
     * @param  list<array<string, mixed>>  $rules
     * @return list<array{cutoff: int, from: int, rules: list<array<string, mixed>>}>
     */
    public static function bands(array $rules): array
    {
        $bands = [];
        $floor = 0;
        $i = 0;
        foreach (self::BANDS as $cutoff) {
            $chunk = [];
            while ($i < count($rules)) {
                $chunk[] = $rules[$i];
                $i++;
                if ($rules[$i - 1]['cum_pct'] >= $cutoff) {
                    break;
                }
            }
            if ($chunk !== []) {
                $bands[] = ['cutoff' => $cutoff, 'from' => $floor, 'rules' => $chunk];
            }
            $floor = $cutoff;
        }

        return $bands;
    }

    /**
     * Teaching-order groups for the curriculum view (H6304): the baked rules
     * re-ordered by kosha `sandhi-curriculum`, grouped into graded lessons 1..9
     * and ending with the ungraded tail (rules the curriculum demoted to lesson
     * 10 but the page still bakes for its 90%-frequency coverage). Each rule
     * keeps its full frequency-layer row plus `curriculum_rank`.
     *
     * @param  list<array<string, mixed>>  $rules
     * @param  array<string, mixed>  $curriculum
     * @return list<array{lesson: int|null, rules: list<array<string, mixed>>}>
     */
    public static function teachingGroups(array $rules, array $curriculum): array
    {
        $byRank = collect($rules)->keyBy('rank');

        $groups = [];
        foreach ($curriculum['teaching_order'] as $entry) {
            $rule = $byRank[$entry['rank']];
            $lesson = $entry['lesson'] <= self::GRADED_LESSONS ? $entry['lesson'] : null;
            if (! isset($groups[$lesson])) {
                $groups[$lesson] = [];
            }
            $rule['curriculum_rank'] = $entry['curriculum_rank'];
            $rule['lesson'] = $entry['lesson'];
            $groups[$lesson][] = $rule;
        }

        // PHP array keys: integer lessons keep insertion order, but the null
        // (tail) key would sort before them — re-split so the tail lands last.
        $tail = $groups[null] ?? [];
        unset($groups[null]);

        return [...array_map(
            fn (int $lesson, array $groupRules) => ['lesson' => $lesson, 'rules' => $groupRules],
            array_keys($groups),
            array_values($groups),
        ), ...($tail !== [] ? [['lesson' => null, 'rules' => $tail]] : [])];
    }
}
