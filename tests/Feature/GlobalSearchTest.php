<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\Course;
use App\Models\LandingPage;
use App\Models\Teacher;
use App\Support\SearchIndexBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H7590 — глобальный поиск в шапке: клиентский индекс (builder/команда),
 * серверная страница /poisk/{q} и наличие бокса поиска на публичных страницах.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedCorpus(): void
    {
        LandingPage::create([
            'title' => 'Йога-сутры: вводный курс',
            'slug' => 'yoga-sutry',
            'is_active' => true,
            'is_listed' => true,
        ]);

        Course::create([
            'title' => 'Йога и санскрит',
            'slug' => 'yoga-i-sanskrit',
            'is_visible' => true,
        ]);

        $teacher = Teacher::create([
            'name' => 'Костина Екатерина',
            'page_enabled' => true,
            'page_slug' => 'katerina-kostina',
            'page_role' => 'Преподаватель хинди',
        ]);

        $category = ArticleCategory::create(['name' => 'Грамматика', 'slug' => 'grammatika']);
        Article::create([
            'category_id' => $category->id,
            'title' => 'Йога в панчанге',
            'slug' => 'yoga-v-panchange',
            'body' => 'Текст статьи о йоге.',
            'is_published' => true,
            'published_at' => now()->subDay(),
        ]);

        // Не должен попадать в корпус: невидимый курс, нелистованный лендинг,
        // выключенный препод, неопубликованная статья.
        Course::create(['title' => 'Секретный курс', 'slug' => 'secret', 'is_visible' => false]);
        LandingPage::create(['title' => 'Вебинар', 'slug' => 'vebinar', 'is_active' => true, 'is_listed' => false]);
        Teacher::create(['name' => 'Невидимый Препод', 'page_enabled' => false, 'page_slug' => 'none']);
        Article::create(['title' => 'Черновик йоги', 'slug' => 'draft', 'body' => 'Черновик.', 'is_published' => false]);

        unset($teacher);
    }

    /** @test */
    public function builder_corpus_contains_only_public_items(): void
    {
        $this->seedCorpus();

        $items = app(SearchIndexBuilder::class)->build();

        $this->assertTrue(collect($items)->contains(fn ($i) => $i['url'] === '/yoga-sutry'));
        $this->assertTrue(collect($items)->contains(fn ($i) => $i['url'] === '/k/yoga-i-sanskrit'));
        $this->assertTrue(collect($items)->contains(fn ($i) => $i['url'] === '/prepodavately/katerina-kostina'));
        $this->assertTrue(collect($items)->contains(fn ($i) => $i['url'] === '/s/yoga-v-panchange'));

        $urls = collect($items)->pluck('url');
        $this->assertFalse($urls->contains('/k/secret'));
        $this->assertFalse($urls->contains('/vebinar'));
        $this->assertFalse($urls->contains('/s/draft'));
    }

    /** @test */
    public function builder_writes_hashed_index_and_manifest_and_prunes_old(): void
    {
        $this->seedCorpus();
        $dir = sys_get_temp_dir().'/search-index-'.uniqid();

        $builder = app(SearchIndexBuilder::class);
        $first = $builder->write($dir);

        $this->assertFileExists($dir.'/'.$first['file']);
        $this->assertSame('index-'.substr(sha1((string) file_get_contents($dir.'/'.$first['file'])), 0, 12).'.json', $first['file']);

        $manifest = json_decode((string) file_get_contents($dir.'/manifest.json'), true);
        $this->assertSame($first['file'], $manifest['file']);
        $this->assertSame($first['count'], $manifest['count']);

        // Контент не изменился → тот же файл, старых не осталось.
        $second = $builder->write($dir);
        $this->assertSame($first['file'], $second['file']);
        $this->assertCount(1, glob($dir.'/index-*.json'));

        // Контент изменился → новый файл, старый подчищен.
        Article::first()->update(['title' => 'Йога и календарь']);
        $third = $builder->write($dir);
        $this->assertNotSame($first['file'], $third['file']);
        $this->assertFileDoesNotExist($dir.'/'.$first['file']);

        // Манифест виден blade-хелперу (кэш per-request — сброс внутрипроцессно).
        $this->assertNotNull(SearchIndexBuilder::manifest());
    }

    /** @test */
    public function search_index_command_runs_clean(): void
    {
        $this->seedCorpus();

        $this->artisan('search:index')->assertSuccessful();
    }

    /** @test */
    public function poisk_results_page_is_200_with_matches_and_testid_header(): void
    {
        $this->seedCorpus();

        $response = $this->get('/poisk/'.rawurlencode('йога'));

        $response->assertOk();
        $response->assertSee('data-testid="global-search"', false);
        $response->assertSee('Йога-сутры: вводный курс');
        $response->assertSee('/k/yoga-i-sanskrit', false);
    }

    /** @test */
    public function poisk_results_page_is_200_even_without_matches(): void
    {
        $response = $this->get('/poisk/'.rawurlencode('абракадабра'));

        $response->assertOk();
        $response->assertSee('Ничего не найдено');
    }

    /** @test */
    public function poisk_query_string_redirects_301_to_path_style(): void
    {
        $response = $this->get('/poisk?q='.rawurlencode('йога для начинающих'));

        $response->assertRedirect('/poisk/'.rawurlencode('йога для начинающих'));
        $this->assertSame(301, $response->status());
    }

    /** @test */
    public function poisk_form_page_renders(): void
    {
        $this->get('/poisk')->assertOk()->assertSee('Поиск');
    }

    /**
     * @test
     *
     * @dataProvider publicPagesWithHeader
     */
    public function header_search_box_is_present_on_public_pages(string $path, array $seed = []): void
    {
        $this->seedCorpus();

        $response = $this->get($path);

        $response->assertOk();
        $response->assertSee('data-testid="global-search"', false);
    }

    public static function publicPagesWithHeader(): array
    {
        return [
            'главная' => ['/'],
            'каталог' => ['/online'],
            'блог' => ['/s'],
            'преподаватели' => ['/prepodavately'],
            'курс' => ['/k/yoga-i-sanskrit'],
        ];
    }
}
