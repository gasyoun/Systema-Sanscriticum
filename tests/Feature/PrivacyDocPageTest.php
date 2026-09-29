<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * H5168 — страница /dokumenty/privacy несёт правку §1.5 политики
 * (реестр AI-рисков R7, рулинг MG 19-09-2026): архивная оговорка
 * о хранении расшифровок живых занятий в приватном git-репозитории
 * (GitHub, США; решение MG 27-07-2026) видна на странице рядом с PDF.
 */
class PrivacyDocPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_page_carries_section_15_archival_exception(): void
    {
        $response = $this->get('/dokumenty/privacy');

        $response->assertOk();
        $response->assertSee('Изменение к разделу 1.5');
        $response->assertSee('исключительно на территории Российской Федерации');
        $response->assertSee('приватном');
        $response->assertSee('git-репозитории');
        $response->assertSee('GitHub');
        $response->assertSee('27.07.2026');
    }

    public function test_other_doc_pages_do_not_show_privacy_amendment(): void
    {
        $response = $this->get('/dokumenty/oferta');

        $response->assertOk();
        $response->assertDontSee('Изменение к разделу 1.5');
    }
}
