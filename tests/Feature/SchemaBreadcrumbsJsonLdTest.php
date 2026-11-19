<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Dictionary;
use App\Models\DictionaryWord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H7875 — BreadcrumbList JSON-LD must ship a REAL "@context" key.
 *
 * Regression: the shared partial resources/views/partials/schema-breadcrumbs.blade.php
 * used to build its payload inside a {!! json_encode([...]) !!} echo. Laravel 13's
 * native Blade directive @context(...) (Illuminate\View\Compilers\Concerns\CompilesContexts)
 * matched the literal '@context' array key there and replaced it with compiled PHP
 * source, so every consumer of the partial emitted:
 *
 *   {"<?php $__contextArgs = []; if (context()->has(...)) : ... ?>":"https://schema.org", ...}
 *
 * schema.org then saw no @context at all. The partial is shared by 13+ views
 * (articles, shop, promo, srs, teachers, slovar), so this test pins the contract
 * at its source: render a real /slovar/{slug} page, grab the application/ld+json
 * blocks, json_decode them and assert the breadcrumb graph is usable.
 */
class SchemaBreadcrumbsJsonLdTest extends TestCase
{
    use RefreshDatabase;

    private function makeWord(): DictionaryWord
    {
        $dict = Dictionary::create([
            'name' => 'Словарь Кочергиной',
            'is_active' => true,
        ]);

        return DictionaryWord::create([
            'dictionary_id' => $dict->id,
            'devanagari' => 'सत्',
            'iast' => 'sat',
            'cyrillic' => 'сат',
            'translation' => 'истина, бытие, сущее',
        ]);
    }

    /**
     * Extract every application/ld+json payload from a rendered page.
     *
     * @return list<array{raw: string, data: mixed}>
     */
    private function jsonLdBlocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        $blocks = [];
        foreach ($matches[1] as $raw) {
            $blocks[] = ['raw' => $raw, 'data' => json_decode($raw, true)];
        }

        return $blocks;
    }

    public function test_slovar_page_breadcrumblist_jsonld_carries_real_context(): void
    {
        $this->makeWord();

        $response = $this->get('/slovar/sat')->assertOk();
        $html = $response->getContent();

        // The defect signature itself must never reach the response.
        $this->assertStringNotContainsString('$__contextArgs', $html, 'Blade @context directive source leaked into the page');
        $this->assertStringNotContainsString('<?php', $html, 'Raw PHP leaked into the rendered page');

        $blocks = $this->jsonLdBlocks($html);
        $this->assertNotEmpty($blocks, 'Expected at least one application/ld+json block on /slovar/sat');

        $breadcrumb = null;
        foreach ($blocks as $block) {
            $this->assertNotNull($block['data'], 'JSON-LD block does not decode as JSON: '.$block['raw']);
            if (($block['data']['@type'] ?? null) === 'BreadcrumbList') {
                $breadcrumb = $block['data'];
            }
        }

        $this->assertIsArray($breadcrumb, 'BreadcrumbList JSON-LD block missing on /slovar/sat');

        $this->assertArrayHasKey('@context', $breadcrumb);
        $this->assertSame('https://schema.org', $breadcrumb['@context']);
        $this->assertSame('BreadcrumbList', $breadcrumb['@type']);

        $items = $breadcrumb['itemListElement'] ?? [];
        $this->assertCount(3, $items, 'BreadcrumbList must carry the three ListItem entries');

        $this->assertSame([1, 2, 3], array_column($items, 'position'));
        $this->assertSame('Главная', $items[0]['name']);
        $this->assertSame('Словарь', $items[1]['name']);
        $this->assertSame('sat', $items[2]['name']);
        $this->assertSame(['ListItem'], array_values(array_unique(array_column($items, '@type'))));

        // Same page still ships a valid DefinedTerm graph (byte-unchanged path).
        $definedTerm = null;
        foreach ($blocks as $block) {
            if (($block['data']['@type'] ?? null) === 'DefinedTerm') {
                $definedTerm = $block['data'];
            }
        }
        $this->assertIsArray($definedTerm, 'DefinedTerm JSON-LD block missing');
        $this->assertSame('https://schema.org', $definedTerm['@context'] ?? null);
        $this->assertSame('sa', $definedTerm['inLanguage'] ?? null);
    }
}
