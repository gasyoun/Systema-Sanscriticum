<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SEO P2 Wave-1 (H210): curated-core allowlist (Track A / D1) + Wikidata sameAs
 * matcher (Track B / D4).
 */
class DictionarySeoWave1Test extends TestCase
{
    use RefreshDatabase;

    private function word(array $attrs = []): DictionaryWord
    {
        $dict = Dictionary::create(['name' => 'Словарь Кочергиной', 'is_active' => true]);

        return DictionaryWord::create(array_merge([
            'dictionary_id' => $dict->id,
            'devanagari' => 'धर्म',
            'iast' => 'dharma',
            'cyrillic' => 'дхарма',
            'translation' => 'закон, долг, порядок, устой, справедливость и добродетель',
        ], $attrs));
    }

    // ─────────── Track A — curated-core allowlist gate (D1) ───────────

    public function test_curated_gate_indexes_only_flagged_rows(): void
    {
        config([
            'dictionary_seo.index_enabled' => true,
            'dictionary_seo.gate.curated_only' => true,
            'dictionary_seo.gate.min_translation_length' => 5,
        ]);

        $onCore = $this->word(['is_indexable' => true]);
        $offCore = $this->word(['iast' => 'gaja', 'devanagari' => 'गज', 'is_indexable' => false]);

        $this->assertTrue($onCore->isIndexable());
        $this->assertFalse($offCore->isIndexable());
    }

    public function test_curated_flag_is_ignored_when_curated_only_off(): void
    {
        config([
            'dictionary_seo.index_enabled' => true,
            'dictionary_seo.gate.curated_only' => false,
            'dictionary_seo.gate.min_translation_length' => 5,
        ]);

        $this->assertTrue($this->word(['is_indexable' => false])->isIndexable());
    }

    public function test_min_length_still_gates_even_when_flagged(): void
    {
        config([
            'dictionary_seo.index_enabled' => true,
            'dictionary_seo.gate.curated_only' => true,
            'dictionary_seo.gate.min_translation_length' => 40,
        ]);

        $this->assertFalse($this->word(['translation' => 'закон', 'is_indexable' => true])->isIndexable());
    }

    public function test_mark_core_indexable_command_flags_by_headword_slug(): void
    {
        config([
            'dictionary_seo.index_enabled' => true,
            'dictionary_seo.gate.curated_only' => true,
            'dictionary_seo.gate.min_translation_length' => 5,
        ]);

        $core = $this->word(['iast' => 'ātman', 'devanagari' => 'आत्मन्', 'translation' => 'душа, самость, я, сущность']);
        $notCore = $this->word(['iast' => 'gaja', 'devanagari' => 'गज', 'translation' => 'слон, боевой слон']);

        // List uses the diacritic IAST form; the command must resolve it to the same
        // slug (atman) the URL uses.
        $listPath = tempnam(sys_get_temp_dir(), 'core').'.txt';
        file_put_contents($listPath, "# curated core\nātman\n; comment\n");

        $this->artisan('dictionary:mark-core-indexable', ['file' => $listPath])
            ->assertSuccessful();

        @unlink($listPath);

        $this->assertTrue($core->fresh()->is_indexable);
        $this->assertFalse($notCore->fresh()->is_indexable);
        $this->assertTrue($core->fresh()->isIndexable());
    }

    public function test_mark_core_indexable_reset_demotes_dropped_headwords(): void
    {
        $a = $this->word(['iast' => 'ātman', 'devanagari' => 'आत्मन्', 'is_indexable' => true]);
        $b = $this->word(['iast' => 'dharma', 'devanagari' => 'धर्म', 'is_indexable' => true]);

        $listPath = tempnam(sys_get_temp_dir(), 'core').'.txt';
        file_put_contents($listPath, "dharma\n"); // ātman dropped from the list

        $this->artisan('dictionary:mark-core-indexable', ['file' => $listPath, '--reset' => true])
            ->assertSuccessful();

        @unlink($listPath);

        $this->assertFalse($a->fresh()->is_indexable); // demoted back to noindex
        $this->assertTrue($b->fresh()->is_indexable);
    }

    // ─────────── Track B — Wikidata sameAs matcher (D4) ───────────

    public function test_matcher_writes_qid_on_exact_sanskrit_label(): void
    {
        Http::fake([
            'www.wikidata.org/*' => Http::response([
                'search' => [[
                    'id' => 'Q9174',
                    'label' => 'dharma',
                    'description' => 'key concept in Indian philosophy and religion',
                    'match' => ['type' => 'label', 'language' => 'sa', 'text' => 'धर्म'],
                ]],
            ]),
        ]);

        $word = $this->word(['wikidata_qid' => null]);

        $this->artisan('dictionary:match-wikidata', ['--write' => true, '--sleep' => 0])
            ->assertSuccessful();

        $this->assertSame('Q9174', $word->fresh()->wikidata_qid);
    }

    public function test_matcher_leaves_qid_null_on_weak_iast_only_signal(): void
    {
        // Devanāgarī search returns nothing exact; IAST search hits an English label.
        Http::fake([
            'www.wikidata.org/*' => Http::response([
                'search' => [[
                    'id' => 'Q42',
                    'label' => 'dharma',
                    'description' => 'unrelated english homograph',
                    'match' => ['type' => 'label', 'language' => 'en', 'text' => 'dharma'],
                ]],
            ]),
        ]);

        $word = $this->word(['wikidata_qid' => null]);

        // Default threshold (1.0) admits only the exact Sanskrit signal → no write.
        $this->artisan('dictionary:match-wikidata', ['--write' => true, '--sleep' => 0])
            ->assertSuccessful();

        $this->assertNull($word->fresh()->wikidata_qid);
    }

    public function test_matcher_rejects_exact_match_that_is_a_band_or_person(): void
    {
        // wbsearchentities returns an exact Devanāgarī hit, but its P31 says it is a
        // musical group (the निर्वाण → Nirvana-the-band collision). Must NOT write.
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'wbgetentities')) {
                return Http::response(['entities' => ['Q11649' => [
                    'claims' => ['P31' => [[
                        'mainsnak' => ['datavalue' => ['value' => ['id' => 'Q215380']]], // musical group
                    ]]],
                ]]]);
            }

            return Http::response(['search' => [[
                'id' => 'Q11649',
                'label' => 'Nirvana',
                'description' => 'American rock band',
                'match' => ['type' => 'label', 'language' => 'sa', 'text' => 'निर्वाण'],
            ]]]);
        });

        $word = $this->word(['iast' => 'nirvāṇa', 'devanagari' => 'निर्वाण', 'wikidata_qid' => null]);

        $this->artisan('dictionary:match-wikidata', ['--write' => true, '--sleep' => 0])
            ->assertSuccessful();

        $this->assertNull($word->fresh()->wikidata_qid);
    }

    public function test_matcher_writes_when_instance_of_is_a_concept(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'wbgetentities')) {
                return Http::response(['entities' => ['Q9174' => [
                    'claims' => ['P31' => [[
                        'mainsnak' => ['datavalue' => ['value' => ['id' => 'Q151885']]], // concept
                    ]]],
                ]]]);
            }

            return Http::response(['search' => [[
                'id' => 'Q9174',
                'label' => 'dharma',
                'match' => ['type' => 'label', 'language' => 'sa', 'text' => 'धर्म'],
            ]]]);
        });

        $word = $this->word(['wikidata_qid' => null]);

        $this->artisan('dictionary:match-wikidata', ['--write' => true, '--sleep' => 0])
            ->assertSuccessful();

        $this->assertSame('Q9174', $word->fresh()->wikidata_qid);
    }

    public function test_matcher_proposes_without_writing_when_write_flag_absent(): void
    {
        // Even a clean exact concept match must NOT persist without --write (D4 safe-default).
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'wbgetentities')) {
                return Http::response(['entities' => ['Q9174' => [
                    'claims' => ['P31' => [[
                        'mainsnak' => ['datavalue' => ['value' => ['id' => 'Q151885']]],
                    ]]],
                ]]]);
            }

            return Http::response(['search' => [[
                'id' => 'Q9174',
                'label' => 'dharma',
                'match' => ['type' => 'label', 'language' => 'sa', 'text' => 'धर्म'],
            ]]]);
        });

        $word = $this->word(['wikidata_qid' => null]);

        $this->artisan('dictionary:match-wikidata', ['--sleep' => 0])
            ->assertSuccessful();

        $this->assertNull($word->fresh()->wikidata_qid);
    }

    public function test_matcher_is_idempotent_skipping_already_set_rows(): void
    {
        Http::fake(['www.wikidata.org/*' => Http::response(['search' => []])]);

        $word = $this->word(['wikidata_qid' => 'Q9174']);

        $this->artisan('dictionary:match-wikidata', ['--sleep' => 0])
            ->assertSuccessful();

        // No HTTP hit should have been needed for an already-populated row.
        Http::assertNothingSent();
        $this->assertSame('Q9174', $word->fresh()->wikidata_qid);
    }
}
