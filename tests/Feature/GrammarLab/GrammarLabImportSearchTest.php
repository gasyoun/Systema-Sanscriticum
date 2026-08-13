<?php

declare(strict_types=1);

namespace Tests\Feature\GrammarLab;

use App\Models\GrammarBookmark;
use App\Models\GrammarLabEntitlement;
use App\Models\GrammarTopic;
use App\Services\GrammarLab\GrammarLabEmbedding;
use App\Services\GrammarLab\GrammarLabEvaluator;
use App\Services\GrammarLab\GrammarLabImporter;
use App\Services\GrammarLab\GrammarLabSearch;

class GrammarLabImportSearchTest extends GrammarLabTestCase
{
    public function test_import_is_idempotent_and_preserves_bookmarks(): void
    {
        $first = $this->importFixture();
        $this->assertSame(2, $first['upserted']);
        $this->assertSame(0, $first['unchanged']);

        $user = $this->student();
        GrammarBookmark::query()->create([
            'user_id' => $user->id,
            'topic_id' => 'subject:grammar-lab:ablaut-grades',
        ]);

        $second = app(GrammarLabImporter::class)->import($this->fixtureDir());
        $this->assertSame(0, $second['upserted']);
        $this->assertSame(2, $second['unchanged']);
        $this->assertSame(1, GrammarBookmark::query()->count());
        $this->assertSame(2, GrammarTopic::query()->where('status', 'published')->count());
    }

    public function test_removed_topic_is_retired_not_deleted(): void
    {
        $this->importFixture();
        $dir = $this->fixtureDir();
        $bundle = json_decode((string) file_get_contents($dir.'/grammar_lab.json'), true);
        $bundle['topics'] = array_values(array_filter(
            $bundle['topics'],
            fn ($t) => $t['id'] !== 'subject:grammar-lab:set-anit-vet'
        ));
        file_put_contents($dir.'/grammar_lab.json', json_encode($bundle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sha = hash_file('sha256', $dir.'/grammar_lab.json');
        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $manifest['feeds'][0]['sha256'] = $sha;
        file_put_contents($dir.'/manifest.json', json_encode($manifest));

        $user = $this->student();
        GrammarBookmark::query()->create([
            'user_id' => $user->id,
            'topic_id' => 'subject:grammar-lab:set-anit-vet',
        ]);

        $report = app(GrammarLabImporter::class)->import($dir);
        $this->assertSame(1, $report['retired']);
        $retired = GrammarTopic::query()->where('topic_id', 'subject:grammar-lab:set-anit-vet')->first();
        $this->assertSame('retired', $retired->status);
        $this->assertSame(1, GrammarBookmark::query()->count());
        $this->enableLab();
        $viewer = $this->student();
        GrammarLabEntitlement::query()->create([
            'user_id' => $viewer->id,
            'grant_kind' => 'pilot',
        ]);
        $this->actingAs($viewer)
            ->get('/dvaram/grammar-lab/t/set-anit-vet')
            ->assertNotFound();
    }

    public function test_lexical_search_hits_four_scripts(): void
    {
        $this->enableLab();
        $this->importFixture();
        $search = app(GrammarLabSearch::class);
        foreach (['гуна', 'guṇa', 'गुण', 'guRa'] as $q) {
            $hits = $search->search($q, 5, false);
            $this->assertNotEmpty($hits, $q);
            $this->assertSame('subject:grammar-lab:ablaut-grades', $hits[0]['topic_id'], $q);
        }
    }

    public function test_semantic_flag_off_does_not_use_vectors(): void
    {
        $this->enableLab();
        config(['features.grammar_lab_semantic' => false]);
        $this->importFixture();
        $this->assertFalse(app(GrammarLabSearch::class)->semanticAvailable());
    }

    public function test_charngram_hash_is_384_and_unit_norm(): void
    {
        $vec = GrammarLabEmbedding::hashProject('гуна guṇa गुण guRa');
        $this->assertCount(384, $vec);
        $norm = sqrt(array_reduce($vec, fn ($s, $x) => $s + $x * $x, 0.0));
        $this->assertEqualsWithDelta(1.0, $norm, 1e-6);
    }

    public function test_entitled_search_json_and_compare_and_bookmark(): void
    {
        $this->enableLab();
        $this->importFixture();
        $user = $this->student();
        GrammarLabEntitlement::query()->create([
            'user_id' => $user->id,
            'grant_kind' => 'pilot',
        ]);

        $this->actingAs($user)
            ->getJson('/dvaram/grammar-lab/search?q=гуна&format=json')
            ->assertOk()
            ->assertJsonPath('results.0.topic_id', 'subject:grammar-lab:ablaut-grades')
            ->assertJsonMissingPath('vectors');

        $this->actingAs($user)
            ->get('/dvaram/grammar-lab/t/ablaut-grades/compare')
            ->assertOk()
            ->assertSee('whitney-sec:235-243')
            ->assertSee('zalizniak-1975:three-grade-alternation');

        $this->actingAs($user)
            ->post('/dvaram/grammar-lab/t/ablaut-grades/bookmark')
            ->assertRedirect();
        $this->assertSame(1, GrammarBookmark::query()->count());

        $this->actingAs($user)
            ->get('/dvaram/grammar-lab/history')
            ->assertOk()
            ->assertSee('Три ступени чередования');
    }

    public function test_frozen_vendor_eval_meets_lexical_gate_when_vendored(): void
    {
        $vendor = resource_path('data/grammar_lab');
        $queries = $vendor.'/frozen_queries.json';
        $bundle = $vendor.'/grammar_lab.json';
        if (! is_file($queries) || ! is_file($bundle)) {
            $this->markTestSkipped('G1 vendor not copied yet');
        }

        app(GrammarLabImporter::class)->import($vendor);
        $report = app(GrammarLabEvaluator::class)->evaluate($queries, false);
        $this->assertGreaterThanOrEqual(100, $report['n']);
        $this->assertTrue($report['gate_pass'], 'Recall@5='.$report['recall_at_5']);
        $this->assertFalse($report['semantic_available']);
    }
}
