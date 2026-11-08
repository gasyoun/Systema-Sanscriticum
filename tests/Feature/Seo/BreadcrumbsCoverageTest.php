<?php

declare(strict_types=1);

namespace Tests\Feature\Seo;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Покрытие BreadcrumbList на хабах и коммерческих точках входа (19-10-2026).
 *
 * Аудит 05-10-2026 нашёл 26 URL без крошек; курс/статья/лендинг их уже имели,
 * а каталог, категории, преподаватели, блог, колоды, реестр сертификатов и
 * «Три направления» — нет. Тест держит именно эти семейства.
 */
class BreadcrumbsCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_emits_breadcrumbs(): void
    {
        $this->get('/online')
            ->assertOk()
            ->assertSee('"BreadcrumbList"', false)
            ->assertSee('Курсы', false);
    }

    public function test_category_page_emits_named_breadcrumb(): void
    {
        Category::factory()->create(['slug' => 'grammatika', 'name' => 'Грамматика']);

        $this->get('/online/kategoriya/grammatika')
            ->assertOk()
            ->assertSee('"BreadcrumbList"', false)
            ->assertSee('Грамматика', false);
    }

    public function test_hubs_emit_breadcrumbs(): void
    {
        foreach (['/prepodavately', '/s', '/online/put', '/sanskritorium'] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('"BreadcrumbList"', false);
        }
    }

    public function test_organization_declares_contact_point(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('"contactPoint"', $html);
        $this->assertStringContainsString('+7-918-192-50-84', $html);
        $this->assertStringContainsString('rusamskrtam@yandex.ru', $html);
    }
}
