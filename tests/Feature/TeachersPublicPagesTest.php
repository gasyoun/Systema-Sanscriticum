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
     * Блоки ld+json вытащены из HTML и декодированы. JSON_THROW_ON_ERROR
     * роняет тест, если в блоке не JSON (например, HTML-экранированный
     * {{ }}-вывод вида {&quot;@context&quot;...} — регрессия до 02-10-2026).
     *
     * @return array<int, array<string, mixed>>
     */
    private function ldJsonBlocks(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        return array_map(
            static fn (string $json): array => json_decode($json, true, 512, JSON_THROW_ON_ERROR),
            $matches[1]
        );
    }

    public function test_index_emits_raw_parseable_json_ld_with_unescaped_names(): void
    {
        Teacher::factory()->create([
            'name' => 'Иван "Толчельников" अर्जुन',
            'page_enabled' => true,
            'page_slug' => 'ivan-tolchelnikov',
            'page_role' => 'Преподаватель санскритской грамматики',
        ]);

        $response = $this->get('/prepodavately')->assertOk();

        // Пейлоад больше не прогнан через e(): HTML-экранированной формы нет.
        $response->assertDontSee('&quot;@context&quot;', false);

        $itemList = collect($this->ldJsonBlocks($response->getContent()))
            ->first(fn (array $schema) => ($schema['@type'] ?? null) === 'ItemList');

        $this->assertNotNull($itemList, 'ItemList JSON-LD отсутствует или невалиден');
        $this->assertSame('https://schema.org', $itemList['@context']);
        $this->assertSame(
            'Иван "Толчельников" अर्जुन',
            $itemList['itemListElement'][0]['item']['name']
        );
    }

    public function test_show_emits_raw_parseable_json_ld_person_that_cannot_break_script_tag(): void
    {
        Teacher::factory()->create([
            // Кавычки + деванагари + попытка порвать <script> изнутри имени:
            // HEX-флаги json_encode экранируют всё это как \uXXXX.
            'name' => 'Махатма "Ом" अर्जुन</script>',
            'page_enabled' => true,
            'page_slug' => 'mahatma-om',
            'page_role' => 'Преподаватель хинди',
        ]);

        $response = $this->get('/prepodavately/mahatma-om')->assertOk();

        $response->assertDontSee('&quot;@context&quot;', false);

        $schemas = $this->ldJsonBlocks($response->getContent());
        // Если бы имя порвало тег, второй блок не распарсился бы целиком —
        // оба (Person + BreadcrumbList) должны декодироваться.
        $person = collect($schemas)->first(fn (array $schema) => ($schema['@type'] ?? null) === 'Person');

        $this->assertNotNull($person, 'Person JSON-LD отсутствует или невалиден');
        $this->assertSame('https://schema.org', $person['@context']);
        $this->assertSame('Махатма "Ом" अर्जुन</script>', $person['name']);
    }
}
