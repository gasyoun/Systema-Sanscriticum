<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Models\Article;
use App\Models\ArticleCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * /s каталог статей: словесные пути вместо ?category=/?q= (H3093-паттерн,
 * расширен со /online на /s). Покрывает App\Support\ArticlesCatalogUrl +
 * ArticleController::index facet-ветку.
 */
class ArticlesPrettyUrlsTest extends TestCase
{
    use RefreshDatabase;

    private function makeArticle(array $attrs = []): Article
    {
        return Article::create(array_merge([
            'slug' => 'article-'.Str::lower(Str::random(8)),
            'title' => 'Grammar Basics Zzz',
            'excerpt' => 'Короткий анонс.',
            'body' => '<p>Тело статьи.</p>',
            'reading_time' => 5,
            'is_published' => true,
            'published_at' => now()->subDay(),
        ], $attrs));
    }

    public function test_bare_index_is_indexable_and_self_canonical(): void
    {
        $html = $this->get('/s')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/s').'">', $html);
        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
    }

    public function test_category_path_shows_only_matching_articles_and_is_indexable(): void
    {
        $grammar = ArticleCategory::create(['name' => 'Грамматика', 'slug' => 'grammatika']);
        $other = ArticleCategory::create(['name' => 'Философия', 'slug' => 'filosofiia']);

        $this->makeArticle(['title' => 'Grammar Article Zzz', 'category_id' => $grammar->id]);
        $this->makeArticle(['title' => 'Philosophy Article Yyy', 'category_id' => $other->id]);

        $html = $this->get('/s/rubrika/grammatika')
            ->assertOk()
            ->assertSee('Grammar Article Zzz')
            ->assertDontSee('Philosophy Article Yyy')
            ->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/s/rubrika/grammatika').'">', $html);
        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
    }

    public function test_legacy_category_query_redirects_to_pretty_path(): void
    {
        ArticleCategory::create(['name' => 'Грамматика', 'slug' => 'grammatika']);

        $this->get('/s?category=grammatika')
            ->assertRedirect('/s/rubrika/grammatika')
            ->assertStatus(301);
    }

    public function test_legacy_search_query_redirects_to_pretty_path(): void
    {
        $this->get('/s?q=grammar+basics')
            ->assertRedirect('/s/poisk/grammar-basics')
            ->assertStatus(301);
    }

    public function test_search_facet_is_not_indexable_and_folds_canonical_to_bare_index(): void
    {
        $this->makeArticle(['title' => 'Grammar Article Zzz']);

        $html = $this->get('/s/poisk/grammar')->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/s').'">', $html);
    }

    public function test_category_combined_with_search_folds_canonical_to_category_only(): void
    {
        $grammar = ArticleCategory::create(['name' => 'Грамматика', 'slug' => 'grammatika']);

        $html = $this->get('/s/rubrika/grammatika/poisk/basics')->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/s/rubrika/grammatika').'">', $html);
    }

    public function test_unknown_category_slug_is_a_real_404(): void
    {
        $this->get('/s/rubrika/does-not-exist')->assertNotFound();
    }

    public function test_unknown_facet_keyword_is_a_real_404(): void
    {
        $this->get('/s/bogus/x')->assertNotFound();
    }

    public function test_pagination_still_works_appended_to_pretty_path(): void
    {
        $grammar = ArticleCategory::create(['name' => 'Грамматика', 'slug' => 'grammatika']);
        for ($i = 1; $i <= 10; $i++) {
            $this->makeArticle(['title' => "Grammar Article {$i}", 'category_id' => $grammar->id]);
        }

        $this->get('/s/rubrika/grammatika?page=2')->assertOk();
    }
}
