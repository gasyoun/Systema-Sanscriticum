<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\View\View;
use RuntimeException;

/**
 * H959 — Rung B1 of the kosha->Systema last-mile pipeline (Hop A: reader-as-a-service).
 *
 * Renders a VENDORED static reading pack — resources/data/kosha_reading_pack_nala_1.json,
 * derived from kosha's dcs-reading-pack-nala-1 (our own derived data, no live dependency
 * on kosha or a sibling-repo path). Every token carries lemma/morph/gloss inline in the
 * feed, so word-tap disclosure needs no external link or runtime lookup — see
 * SanskritGrammar docs/LAST_MILE_PIPELINE_SPEC.md §1's vendored-file ruling.
 *
 * Gated by config('features.kosha_reader'); OFF by default — the route 404s exactly like
 * /slovar did before its own Wave 0 (H204).
 */
class ReadingPackController extends Controller
{
    private const FEED_PATH = 'data/kosha_reading_pack_nala_1.json';

    public function show(): View
    {
        abort_if(! config('features.kosha_reader', false), 404);

        return view('reading.kosha-demo', ['pack' => $this->readPack()]);
    }

    /**
     * @return array{slug:string,title:string,ref:string,text_name:string,source:string,stats:array,sentences:list<array>}
     */
    private function readPack(): array
    {
        $path = resource_path(self::FEED_PATH);

        if (! is_file($path)) {
            throw new RuntimeException("Reading pack feed not found at {$path}");
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("Cannot read {$path}");
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['sentences'])) {
            throw new RuntimeException("Invalid or malformed reading pack at {$path}");
        }

        return $data;
    }
}
