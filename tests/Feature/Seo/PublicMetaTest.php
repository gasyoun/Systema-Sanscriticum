<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Category;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\SrsCard;
use App\Models\SrsDeck;
use App\Models\SrsNoteType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * SEO-META-1 gate: public pages must ship a non-empty meta description, category
 * facets must not reuse the catalog title, and noindex deck shells must stay
 * out of the sitemap.
 */
class PublicMetaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('SRS_ENABLED=true');
        $_ENV['SRS_ENABLED'] = 'true';
        $_SERVER['SRS_ENABLED'] = 'true';

        parent::setUp();

        config(['srs.enabled' => true]);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        putenv('SRS_ENABLED');
        unset($_ENV['SRS_ENABLED'], $_SERVER['SRS_ENABLED']);

        parent::tearDown();
    }

    public function test_category_facet_has_its_own_title_and_description(): void
    {
        $category = Category::factory()->create([
            'name' => 'Грамматика',
            'slug' => 'grammatika',
        ]);
        $course = Course::factory()->create([
            'slug' => 'grammatika-sanskrita',
            'title' => 'Грамматика санскрита',
        ]);
        $course->categories()->attach($category);

        $this->get('/online')
            ->assertOk()
            ->assertSee('<title>Курсы санскрита онлайн |', false)
            ->assertSee('name="description" content="Каталог курсов санскрита', false);

        $html = $this->get('/online/kategoriya/grammatika')
            ->assertOk()
            ->assertSee('<title>Курсы санскрита: Грамматика |', false)
            ->assertSee('name="description" content="Курсы санскрита и хинди по направлению «Грамматика»', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#<h1[^>]*>\s*Курсы санскрита: Грамматика\s*</h1>#u',
            $html
        );
    }

    public function test_course_without_description_still_gets_one(): void
    {
        Course::factory()->create([
            'slug' => 'kurs-bez-opisaniya',
            'title' => 'Санскрит без описания',
            'description' => null,
            'meta_description' => null,
        ]);

        $html = $this->get('/k/kurs-bez-opisaniya')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('name="description" content=""', $html);
        $this->assertMatchesRegularExpression(
            '#<meta name="description" content="Курс санскрита «Санскрит без описания»#u',
            $html
        );
    }

    public function test_deck_page_gets_per_deck_meta(): void
    {
        $this->makeDeck('sanskrit-core', 'Санскрит — ядро');

        $html = $this->get('/koloda/sanskrit-core')
            ->assertOk()
            ->assertSee('<title>Колода «Санскрит — ядро» — карточки санскрита |', false)
            ->assertDontSee('noindex, follow')
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#<meta name="description" content="Публичная колода карточек «Санскрит — ядро»#u',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<link rel="canonical" href="[^"]*/koloda/sanskrit-core">#',
            $html
        );
    }

    public function test_level_shells_are_noindex_and_not_in_sitemap(): void
    {
        $this->makeDeck('deck-level-1', 'Level 1');
        $this->makeDeck('deck-level-2', 'Level 2');

        $this->get('/koloda/deck-level-2')
            ->assertOk()
            ->assertSee('noindex, follow');

        $xml = $this->get('/sitemap.xml')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('/koloda/deck-level-1', $xml);
        $this->assertStringNotContainsString('/koloda/deck-level-2', $xml);
    }

    public function test_koloda_language_hubs_have_unique_titles(): void
    {
        $this->makeDeck('sanskrit-core', 'Санскрит — ядро');

        $this->get('/koloda')
            ->assertOk()
            ->assertSee('<title>Колоды карточек по санскриту — открытая галерея |', false);

        $this->get('/koloda/yazyk/hindi')
            ->assertOk()
            ->assertSee('<title>Колоды карточек по хинди — открытая галерея |', false);

        $this->get('/koloda/yazyk/vse')
            ->assertOk()
            ->assertSee('<title>Колоды карточек по санскриту и хинди — открытая галерея |', false);

        $html = $this->get('/koloda/yazyk/sanskrit')
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '#<link rel="canonical" href="[^"]*/koloda">#',
            $html
        );
    }

    public function test_landing_page_gets_meta_description(): void
    {
        LandingPage::create([
            'slug' => 'probnyi-vebinar',
            'title' => 'Пробный вебинар',
            'description' => 'Описание пробного вебинара по санскриту.',
            'is_active' => true,
        ]);

        $this->get('/probnyi-vebinar')
            ->assertOk()
            ->assertSee('name="description" content="Описание пробного вебинара по санскриту."', false);
    }

    public function test_sitemap_has_no_duplicate_locations(): void
    {
        $category = Category::factory()->create(['slug' => 'grammatika']);
        $course = Course::factory()->create([
            'slug' => 'grammatika-sanskrita',
            'title' => 'Грамматика санскрита',
        ]);
        $course->categories()->attach($category);
        $this->makeDeck('sanskrit-core', 'Санскрит — ядро');

        $xml = $this->get('/sitemap.xml')
            ->assertOk()
            ->getContent();

        preg_match_all('#<loc>([^<]+)</loc>#', $xml, $matches);

        $this->assertSame($matches[1], array_values(array_unique($matches[1])));
    }

    private function makeDeck(string $slug, string $name): SrsDeck
    {
        $noteType = SrsNoteType::firstOrCreate(
            ['key' => 'basic_sa'],
            [
                'name' => 'Sanskrit',
                'language' => 'sa',
                'fields' => ['devanagari', 'iast', 'translation'],
            ]
        );

        $deck = SrsDeck::create([
            'note_type_id' => $noteType->id,
            'name' => $name,
            'slug' => $slug,
            'language' => 'sa',
            'visibility' => 'system',
        ]);

        SrsCard::create([
            'deck_id' => $deck->id,
            'direction' => 'front_back',
            'fields' => ['iast' => 'word1', 'translation' => 'перевод1'],
        ]);

        return $deck;
    }
}
