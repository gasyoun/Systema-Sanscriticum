<?php

namespace Tests\Feature;

use App\Models\Course;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Публичные страницы преподавателей ОРС: список /prepodavately и анкета
 * /prepodavately/{slug}. Наружу уходит только включённое в Filament
 * (page_enabled + page_slug), контент проходит SanitizedHtml.
 */
class TeachersPublicPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_enabled_teacher_pages(): void
    {
        Teacher::factory()->create([
            'name' => 'Иван Толчельников',
            'page_enabled' => true,
            'page_slug' => 'ivan-tolchelnikov',
            'page_role' => 'Преподаватель санскритской грамматики и индийской философии',
            'page_excerpt' => 'Ведёт грамматику и философию с 2000-х.',
        ]);

        Teacher::factory()->create([
            'name' => 'Скрытый Преподаватель',
            'page_enabled' => false,
            'page_slug' => 'skrytyy-prepodavatel',
        ]);

        $this->get('/prepodavately')
            ->assertOk()
            ->assertSee('Иван Толчельников')
            ->assertSee('Преподаватель санскритской грамматики и индийской философии')
            ->assertSee('/prepodavately/ivan-tolchelnikov', false)
            ->assertDontSee('Скрытый Преподаватель');
    }

    public function test_index_is_200_without_any_enabled_pages(): void
    {
        $this->get('/prepodavately')->assertOk();
    }

    public function test_show_renders_role_content_facts_and_socials(): void
    {
        Teacher::factory()->create([
            'name' => 'Иван Толчельников',
            'page_enabled' => true,
            'page_slug' => 'ivan-tolchelnikov',
            'page_role' => 'Преподаватель санскритской грамматики',
            'page_excerpt' => 'Короткий анонс.',
            'page_html' => '<h3>Образование и академический путь</h3><p>Мехмат МГУ.</p>',
            'page_facts' => [
                ['label' => 'Дата рождения', 'value' => '1978'],
                ['label' => 'Альма-матер', 'value' => 'МГУ'],
            ],
            'youtube_url' => 'https://www.youtube.com/@samskrtamru',
        ]);

        $this->get('/prepodavately/ivan-tolchelnikov')
            ->assertOk()
            ->assertSee('Преподаватель санскритской грамматики')
            ->assertSee('Образование и академический путь')
            ->assertSee('Мехмат МГУ.')
            ->assertSee('Дата рождения')
            ->assertSee('Альма-матер')
            ->assertSee('https://www.youtube.com/@samskrtamru', false)
            ->assertSee('/prepodavately/ivan-tolchelnikov', false);
    }

    public function test_show_is_404_for_disabled_or_unknown_slug(): void
    {
        Teacher::factory()->create([
            'page_enabled' => false,
            'page_slug' => 'skrytyy-prepodavatel',
        ]);

        $this->get('/prepodavately/skrytyy-prepodavatel')->assertNotFound();
        $this->get('/prepodavately/net-takogo')->assertNotFound();
    }

    public function test_page_html_is_sanitized_on_render(): void
    {
        Teacher::factory()->create([
            'page_enabled' => true,
            'page_slug' => 'xss-check',
            'page_html' => '<p>Безопасно</p><script>window.alert(1)</script>',
        ]);

        $this->get('/prepodavately/xss-check')
            ->assertOk()
            ->assertSee('Безопасно')
            // В ответе есть легитимные <script> лейаута — проверяем, что именно
            // инжектированный код не дошёл до рендера (SanitizedHtml срезал тег):
            // ни в теле страницы, ни в <meta description>, собираемом из контента.
            ->assertDontSee('window.alert(1)', false);
    }

    public function test_show_lists_only_visible_courses_of_teacher(): void
    {
        $teacher = Teacher::factory()->create([
            'page_enabled' => true,
            'page_slug' => 'ivan-tolchelnikov',
        ]);

        $visible = Course::factory()->create([
            'title' => 'Санскрит с нуля',
            'teacher_id' => $teacher->id,
            'is_visible' => true,
            'is_active' => true,
        ]);

        Course::factory()->create([
            'title' => 'Скрытый курс без витрины',
            'teacher_id' => $teacher->id,
            'is_visible' => false,
        ]);

        $this->get('/prepodavately/ivan-tolchelnikov')
            ->assertOk()
            ->assertSee('Санскрит с нуля')
            ->assertSee('/k/'.$visible->slug, false)
            ->assertDontSee('Скрытый курс без витрины');
    }

    public function test_public_page_url_is_null_while_disabled(): void
    {
        $teacher = Teacher::factory()->create([
            'page_enabled' => false,
            'page_slug' => 'ivan-tolchelnikov',
        ]);

        $this->assertNull($teacher->publicPageUrl());

        $teacher->update(['page_enabled' => true]);

        $this->assertSame(url('/prepodavately/ivan-tolchelnikov'), $teacher->publicPageUrl());
    }

    public function test_sitemap_includes_only_enabled_teacher_pages(): void
    {
        Teacher::factory()->create([
            'page_enabled' => true,
            'page_slug' => 'ivan-tolchelnikov',
        ]);

        Teacher::factory()->create([
            'page_enabled' => false,
            'page_slug' => 'skrytyy-prepodavatel',
        ]);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/prepodavately/ivan-tolchelnikov', false)
            ->assertDontSee('skrytyy-prepodavatel', false);
    }

    /**
     * SEO-разметка обязана уходить сырым JSON ({!! !!}), а не через экранирующий
     * {{ }}: иначе браузер получает {&quot;@context&quot;...} и поисковики не
     * парсят схему. HEX-флаги json_encode кодируют <, >, &, ' и " в значениях,
     * поэтому имя с кавычками, деванагари и даже </script> остаётся валидным
     * JSON и не рвёт тег скрипта.
     */
    public function test_json_ld_is_raw_parseable_and_safe_for_names_with_quotes_and_devanagari(): void
    {
        Teacher::factory()->create([
            'name' => 'Панини "Аштадхьяйи" व्याकरण </script>',
            'page_enabled' => true,
            'page_slug' => 'panini-jsonld-check',
            'page_role' => 'Ведёт санскрит',
        ]);

        $index = $this->get('/prepodavately');
        $index->assertOk()
            // Сырой, не &quot;-экранированный payload:
            ->assertSee('"@context"', false);

        $itemList = $this->findSchema(
            $this->decodeLdJson($this->ldJsonBlocks($index->getContent())),
            'ItemList'
        );
        $this->assertNotNull($itemList);
        $this->assertSame(
            'Панини "Аштадхьяйи" व्याकरण </script>',
            $itemList['itemListElement'][0]['item']['name']
        );

        $show = $this->get('/prepodavately/panini-jsonld-check');
        $show->assertOk()
            ->assertSee('"@context"', false);

        $showSchemas = $this->decodeLdJson($this->ldJsonBlocks($show->getContent()));

        $person = $this->findSchema($showSchemas, 'Person');
        $this->assertNotNull($person);
        $this->assertSame('Панини "Аштадхьяйи" व्याकरण </script>', $person['name']);

        $breadcrumbs = $this->findSchema($showSchemas, 'BreadcrumbList');
        $this->assertNotNull($breadcrumbs);
        $this->assertSame(
            'Панини "Аштадхьяйи" व्याकरण </script>',
            $breadcrumbs['itemListElement'][2]['name']
        );
    }

    /**
     * @return list<string> содержимое всех ld+json блоков ответа
     */
    private function ldJsonBlocks(string $html): array
    {
        preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $matches);

        return $matches[1];
    }

    /**
     * Каждый ld+json блок обязан декодироваться — это заодно и проверка,
     * что экранированные значения не порвали структуру payload.
     *
     * @param  list<string>  $blocks
     * @return list<array<string, mixed>>
     */
    private function decodeLdJson(array $blocks): array
    {
        $decoded = [];
        foreach ($blocks as $block) {
            $payload = json_decode(trim($block), true);
            $this->assertIsArray($payload, 'ld+json не парсится: '.substr($block, 0, 200));
            $decoded[] = $payload;
        }

        return $decoded;
    }

    /**
     * @param  list<array<string, mixed>>  $schemas
     */
    private function findSchema(array $schemas, string $type): ?array
    {
        foreach ($schemas as $schema) {
            if (($schema['@type'] ?? null) === $type) {
                return $schema;
            }
        }

        return null;
    }
}
