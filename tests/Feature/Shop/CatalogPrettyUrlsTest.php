<?php

declare(strict_types=1);

namespace Tests\Feature\Shop;

use App\Models\Category;
use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /online каталог: словесные пути вместо query string (H3xxx —
 * /online?cat[0]=3 читался как плохой SEO-слаг). Покрывает
 * App\Support\ShopCatalogUrl + ShopController::index facet-ветку.
 */
class CatalogPrettyUrlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_catalog_is_indexable_and_self_canonical(): void
    {
        $html = $this->get('/online')->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/online').'">', $html);
        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
    }

    public function test_category_path_shows_only_matching_courses_and_is_indexable(): void
    {
        $grammar = Category::factory()->create(['slug' => 'grammatika']);
        $other = Category::factory()->create(['slug' => 'hindi']);

        $matching = Course::factory()->create(['title' => 'Grammar Course Zzz']);
        $matching->categories()->attach($grammar->id);

        $nonMatching = Course::factory()->create(['title' => 'Hindi Course Yyy']);
        $nonMatching->categories()->attach($other->id);

        $html = $this->get('/online/kategoriya/grammatika')
            ->assertOk()
            ->assertSee('Grammar Course Zzz')
            ->assertDontSee('Hindi Course Yyy')
            ->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.url('/online/kategoriya/grammatika').'">', $html);
        $this->assertStringContainsString('name="robots" content="index, follow"', $html);
    }

    public function test_legacy_category_query_string_redirects_to_pretty_path(): void
    {
        $category = Category::factory()->create(['slug' => 'grammatika']);

        $this->get('/online?cat[0]='.$category->id)
            ->assertRedirect('/online/kategoriya/grammatika')
            ->assertStatus(301);
    }

    public function test_legacy_format_and_search_query_redirect_to_pretty_path(): void
    {
        $this->get('/online?format=live')
            ->assertRedirect('/online/format/live')
            ->assertStatus(301);

        $this->get('/online?q=grammar+basics')
            ->assertRedirect('/online/poisk/grammar-basics')
            ->assertStatus(301);
    }

    public function test_format_only_facet_is_not_indexable_and_folds_canonical_to_bare_catalog(): void
    {
        $html = $this->get('/online/format/live')->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/online').'">', $html);
    }

    public function test_category_combined_with_format_is_not_indexable_and_folds_to_category_only(): void
    {
        $category = Category::factory()->create(['slug' => 'grammatika']);

        $html = $this->get('/online/kategoriya/grammatika/format/live')->assertOk()->getContent();

        $this->assertStringContainsString('name="robots" content="noindex, follow"', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/online/kategoriya/grammatika').'">', $html);
    }

    public function test_teacher_facet_resolves_by_name_and_filters_courses(): void
    {
        $teacher = Teacher::factory()->create(['name' => 'Иван Петров']);
        $course = Course::factory()->create(['title' => 'Taught Course Www', 'teacher_id' => $teacher->id]);
        $other = Course::factory()->create(['title' => 'Other Teacher Course Vvv']);

        $this->get('/online/prepodavatel/Иван-Петров')
            ->assertOk()
            ->assertSee('Taught Course Www')
            ->assertDontSee('Other Teacher Course Vvv');
    }

    public function test_unknown_category_slug_is_a_real_404(): void
    {
        $this->get('/online/kategoriya/does-not-exist')->assertNotFound();
    }

    public function test_unknown_facet_keyword_is_a_real_404(): void
    {
        $this->get('/online/bogus/x')->assertNotFound();
    }

    public function test_unknown_teacher_name_is_a_real_404(): void
    {
        $this->get('/online/prepodavatel/Nobody-Here')->assertNotFound();
    }
}
