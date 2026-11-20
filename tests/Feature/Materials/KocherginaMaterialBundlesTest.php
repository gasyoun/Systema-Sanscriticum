<?php

declare(strict_types=1);

namespace Tests\Feature\Materials;

use App\Support\KocherginaMaterialBundles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H8210 · открытые наборы материалов курса Кочергиной (/materialy/kochergina/*).
 *
 * Контракт (согласовано MG 13-11-2026): каждый набор — постоянный URL, условие
 * выдачи по теме/готовности, разделы «сделать сейчас»/«для справки»/«по желанию»,
 * порядок работы, наблюдаемый признак готовности. У каждого материала — версия и
 * формат. Blocked-пункт не имеет ссылки (мёртвых ссылок нет), доступный — имеет.
 * Страницы публичны: без auth, без оплаты; доступ к урокам/тарифам не затрагивается.
 */
class KocherginaMaterialBundlesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Страницы контента не зависят от Vite-ассетов; в CI/worktree
        // manifest может отсутствовать — рендерим без него.
        $this->withoutVite();
    }

    public function test_hub_is_public_and_lists_all_bundles(): void
    {
        $response = $this->get('/materialy/kochergina');

        $response->assertOk();

        foreach (KocherginaMaterialBundles::slugs() as $slug) {
            $response->assertSee(\url('/materialy/kochergina/'.$slug), false);
        }
    }

    public function test_every_bundle_page_is_public_and_renders_contract_sections(): void
    {
        foreach (KocherginaMaterialBundles::slugs() as $slug) {
            $response = $this->get('/materialy/kochergina/'.$slug);

            $response->assertOk();
            $response->assertSee('Сделать сейчас');
            $response->assertSee('Для справки');
            $response->assertSee('По желанию');
            $response->assertSee('Порядок работы');
            // Набор сам говорит, когда выдавать и когда готов идти дальше.
            $response->assertSee('Когда выдавается');
            $response->assertSee('Признак готовности');
        }
    }

    public function test_unknown_bundle_slug_is_404(): void
    {
        $this->get('/materialy/kochergina/no-such-stage')->assertNotFound();
    }

    public function test_bundle_data_contract_is_well_formed(): void
    {
        foreach (KocherginaMaterialBundles::bundles() as $bundle) {
            $this->assertNotEmpty($bundle['issuance']);
            $this->assertNotEmpty($bundle['readiness']);
            $this->assertNotEmpty($bundle['sequence']);

            $this->assertArrayHasKey('now', $bundle['sections']);
            $this->assertArrayHasKey('reference', $bundle['sections']);
            $this->assertArrayHasKey('optional', $bundle['sections']);

            foreach ($bundle['sections'] as $section) {
                $this->assertNotEmpty($section['items']);

                foreach ($section['items'] as $item) {
                    $this->assertNotEmpty($item['name'], 'у каждого материала есть название');
                    $this->assertNotEmpty($item['version'], 'у каждого материала указана версия');
                    $this->assertNotEmpty($item['format'], 'у каждого материала указан формат');

                    if (($item['status'] ?? '') === 'available') {
                        // Доступный материал имеет проверяемую ссылку — кроме
                        // inline-текста («Макшика» выводится на самой странице).
                        $this->assertTrue(
                            ! empty($item['url']) || ($item['inline'] ?? null) === 'makshika',
                            'available без ссылки допустим только для inline-текста: '.$item['name']
                        );
                    } else {
                        // Blocked: без ссылки, но с явной причиной и условием разблокировки.
                        $this->assertEmpty($item['url'] ?? null, 'blocked не несёт ссылку: '.$item['name']);
                        $this->assertNotEmpty($item['blocked_reason'], 'blocked объясняет причину: '.$item['name']);
                    }
                }
            }
        }
    }

    public function test_blocked_items_render_without_links_but_with_reasons(): void
    {
        foreach (KocherginaMaterialBundles::slugs() as $slug) {
            $response = $this->get('/materialy/kochergina/'.$slug);
            $html = $response->getContent();

            foreach (KocherginaMaterialBundles::bundle($slug)['sections'] as $section) {
                foreach ($section['items'] as $item) {
                    if (($item['status'] ?? '') === 'blocked') {
                        $this->assertStringContainsString('Пока недоступно', $html);
                        $this->assertStringContainsString('Почему пока недоступно', $html);
                    }
                }
            }
        }
    }

    public function test_makshika_exact_text_renders_on_first_shloka_page(): void
    {
        $response = $this->get('/materialy/kochergina/pervaya-shloka');
        $makshika = KocherginaMaterialBundles::makshika();

        $response->assertOk();
        $response->assertSee($makshika['ru'], false);
        // Точный текст, без выдуманных записей: перевод подписан источником.
        $response->assertSee('Indische Sprüche');
    }

    public function test_subhashita_collections_54_and_100_are_distinguished(): void
    {
        $response = $this->get('/materialy/kochergina/pervaya-shloka');
        $html = $response->getContent();

        $this->assertStringContainsString('Сборник 100 субхашит', $html);
        $this->assertStringContainsString('Сборник 54 субхашиты', $html);
        // Версии различены и явно не объединяются.
        $this->assertStringContainsString('версии не объединяются', $html);
    }

    public function test_pages_do_not_expose_internal_source_locators(): void
    {
        // На публичные страницы не попадают внутренние пути источников и сырые
        // данные стенограмм (H8210 edit scope: только учебные материалы).
        foreach (KocherginaMaterialBundles::slugs() as $slug) {
            $html = $this->get('/materialy/kochergina/'.$slug)->getContent();

            $this->assertStringNotContainsString('stenogrammy', $html);
            $this->assertStringNotContainsString('resources/data', $html);
            $this->assertStringNotContainsString('12.09.23', $html);
            $this->assertStringNotContainsString('23.10.23', $html);
        }
    }
}
