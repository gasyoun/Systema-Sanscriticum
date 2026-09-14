<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DictionaryPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeWord(array $attrs = []): DictionaryWord
    {
        $dict = Dictionary::create([
            'name' => $attrs['dict'] ?? 'Словарь Кочергиной',
            'is_active' => true,
        ]);

        return DictionaryWord::create(array_merge([
            'dictionary_id' => $dict->id,
            'devanagari' => 'सत्',
            'iast' => 'sat',
            'cyrillic' => 'сат',
            'translation' => 'истина, бытие, сущее',
        ], $attrs['word'] ?? []));
    }

    public function test_hub_page_loads_and_lists_words(): void
    {
        $this->makeWord();

        $this->get('/slovar')
            ->assertOk()
            ->assertSee('Словарь санскрита')
            ->assertSee('/slovar/sat');
    }

    public function test_word_page_renders_headword_and_defined_term_jsonld(): void
    {
        $this->makeWord();

        $res = $this->get('/slovar/sat')->assertOk();
        $res->assertSee('истина, бытие, сущее');
        $html = $res->getContent();

        $this->assertStringContainsString('"@type":"DefinedTerm"', $html);
        $this->assertStringContainsString('"inLanguage":"sa"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
    }

    public function test_slug_is_auto_generated_on_save(): void
    {
        $word = $this->makeWord();
        $this->assertSame('sat', $word->fresh()->slug);
    }

    public function test_word_page_is_noindex_in_wave_0(): void
    {
        config(['dictionary_seo.index_enabled' => false]);
        $this->makeWord();

        $this->get('/slovar/sat')
            ->assertOk()
            ->assertSee('name="robots" content="noindex, follow"', false);
    }

    public function test_same_headword_across_dictionaries_collapses_to_one_page(): void
    {
        $d1 = Dictionary::create(['name' => 'Словарь Кочергиной', 'is_active' => true]);
        $d2 = Dictionary::create(['name' => 'Словарь Моньер-Вильямса', 'is_active' => true]);
        DictionaryWord::create(['dictionary_id' => $d1->id, 'iast' => 'dharma', 'devanagari' => 'धर्म', 'translation' => 'закон, долг, порядок']);
        DictionaryWord::create(['dictionary_id' => $d2->id, 'iast' => 'dharma', 'devanagari' => 'धर्म', 'translation' => 'law, duty, righteousness, virtue and merit']);

        $this->get('/slovar/dharma')
            ->assertOk()
            ->assertSee('Словарь Кочергиной')
            ->assertSee('Словарь Моньер-Вильямса')
            ->assertSee('law, duty, righteousness, virtue and merit');
    }

    public function test_unknown_slug_is_404(): void
    {
        $this->get('/slovar/nonexistent-word')->assertNotFound();
    }

    public function test_sitemap_withholds_word_urls_in_wave_0(): void
    {
        config(['dictionary_seo.index_enabled' => false]);
        $this->makeWord();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertDontSee('/slovar/sat');
    }

    public function test_sitemap_includes_words_once_enabled_and_gate_open(): void
    {
        config([
            'dictionary_seo.index_enabled' => true,
            'dictionary_seo.gate.curated_only' => false,
            'dictionary_seo.gate.min_translation_length' => 5,
        ]);
        $this->makeWord();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/slovar/sat');
    }

    public function test_word_page_carries_course_cta(): void
    {
        $this->makeWord();

        $res = $this->get('/slovar/sat')->assertOk();
        $res->assertSee('Хотите читать такие слова сами?');
        $res->assertSee(route('shop.index'));
    }

    public function test_shop_navigation_links_to_dictionary_hub(): void
    {
        $this->get('/verify/no-such-certificate')
            ->assertOk()
            ->assertSee(route('slovar.index'));
    }
}
