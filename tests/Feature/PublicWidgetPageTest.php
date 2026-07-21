<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicWidgetPageTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/widgets/schedule';

    public function test_widget_page_is_public_and_renders_filter_controls(): void
    {
        $response = $this->get(self::URL);

        $response->assertOk();
        // Контролы фильтрации присутствуют в серверном HTML (опции наполняет JS).
        $response->assertSee('Все направления');
        $response->assertSee('Все преподаватели');
        $response->assertSee('sw-filter-direction');
        $response->assertSee('sw-filter-teacher');
        $response->assertSee('sw-schedule');
    }

    public function test_widget_scopes_frame_ancestors_to_samskrtam_only(): void
    {
        $response = $this->get(self::URL);

        $response->assertOk();

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'Виджет должен отдавать заголовок Content-Security-Policy.');
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('samskrtam.ru', $csp);
        // X-Frame-Options не выставляем — он не умеет несколько origin и конфликтует с CSP.
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }
}
