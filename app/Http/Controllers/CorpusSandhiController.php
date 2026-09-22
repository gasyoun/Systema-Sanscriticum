<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * H4718 (census A13) — /reading/sandhi: the sandhi rules a reader actually meets,
 * ranked by frequency across 41 DCS texts (kosha `corpus-sandhi`).
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
}
