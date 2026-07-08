<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use App\Services\SanskritGlossary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H344 — corpus Sa→Ru enrichment on /slovar/{slug}, behind the
 * `features.slovar_enrichment` flag (default OFF).
 */
class DictionaryEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE = __DIR__.'/../fixtures/sa_ru_glossary.fixture.json';

    protected function setUp(): void
    {
        parent::setUp();
        SanskritGlossary::flush();
        // Resolve the controller's SanskritGlossary from a hermetic fixture feed.
        $this->app->instance(SanskritGlossary::class, new SanskritGlossary(self::FIXTURE));
    }

    private function makeWord(string $iast = 'dharma', string $translation = 'закон, долг, порядок'): DictionaryWord
    {
        $dict = Dictionary::create(['name' => 'Словарь Кочергиной', 'is_active' => true]);

        return DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => 'धर्म',
            'iast' => $iast,
            'cyrillic' => 'дхарма',
            'translation' => $translation,
        ]);
    }

    public function test_flag_off_renders_no_enrichment_block(): void
    {
        config(['features.slovar_enrichment' => false]);
        $this->makeWord();

        $res = $this->get('/slovar/dharma')->assertOk();
        $res->assertDontSee('Корпусные значения');
        // JSON-LD description untouched (no corpus glosses spliced in).
        $this->assertStringNotContainsString('корпусные значения', $res->getContent());
    }

    public function test_flag_on_renders_enrichment_block_and_glosses(): void
    {
        config(['features.slovar_enrichment' => true]);
        $this->makeWord();

        $res = $this->get('/slovar/dharma')->assertOk();
        $res->assertSee('Корпусные значения');
        $res->assertSee('дхарма');
        $res->assertSee('долг');
        $res->assertSee('сущ.');   // NOUN → russian POS label
        $res->assertSee('SLP1:');  // transliteration variant surfaced (lemma in a <span lang>)
        $res->assertSee('Darma');
    }

    public function test_flag_on_upgrades_defined_term_description_and_stays_valid_jsonld(): void
    {
        config(['features.slovar_enrichment' => true]);
        $this->makeWord();

        $html = $this->get('/slovar/dharma')->assertOk()->getContent();

        // The DefinedTerm JSON-LD block must still parse as valid JSON…
        $this->assertMatchesRegularExpression('/"@type":"DefinedTerm"/', $html);
        preg_match('#<script type="application/ld\+json">\s*(\{.*?"DefinedTerm".*?\})\s*</script>#s', $html, $m);
        $this->assertNotEmpty($m, 'DefinedTerm JSON-LD script not found');
        $decoded = json_decode($m[1], true);
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'DefinedTerm JSON-LD is not valid JSON');

        // …and its description now carries the corpus glosses.
        $this->assertStringContainsString('корпусные значения', $decoded['description']);
        $this->assertStringContainsString('дхарма', $decoded['description']);
    }

    public function test_unattested_headword_gets_no_enrichment_even_when_flag_on(): void
    {
        config(['features.slovar_enrichment' => true]);
        $this->makeWord('avidyamana-slovo', 'какое-то слово');

        $this->get('/slovar/avidyamana-slovo')
            ->assertOk()
            ->assertDontSee('Корпусные значения');
    }

    public function test_service_lookup_matches_by_normalised_iast(): void
    {
        config(['features.slovar_enrichment' => true]);
        $word = $this->makeWord('Dharma'); // upper-case + no diacritics still matches 'dharma'

        $glossary = new SanskritGlossary(self::FIXTURE);
        $enrichment = $glossary->enrich($word);

        $this->assertNotNull($enrichment);
        $this->assertSame(['дхарма', 'закон', 'долг'], $enrichment['glosses']);
        $this->assertSame('сущ.', $enrichment['pos_label']);
        $this->assertSame(1993, $enrichment['freq']);
    }
}
