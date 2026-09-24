<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Iframe-эмбед веб-чата для samskrtam.ru (H5451): standalone-страница
 * GET /chat/embed без лейаута кабинета, самогейт флагом
 * features.support_chat_embed (OFF → 404), CSP frame-ancestors только
 * samskrtam.ru и только на этом ответе, `?page=` (товар магазина)
 * пробрасывается в виджет для приветствия/телеметрии.
 */
class PublicChatEmbedTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/chat/embed';

    public function test_flag_off_returns_404(): void
    {
        config(['features.support_chat_embed' => false]);

        $this->get(self::URL)->assertNotFound();
        $this->get(self::URL.'?page=https://samskrtam.ru/product/x')->assertNotFound();
    }

    public function test_flag_on_renders_standalone_widget_page(): void
    {
        config(['features.support_chat_embed' => true]);

        $response = $this->get(self::URL.'?page=https://samskrtam.ru/product/x');

        $response->assertOk();
        // Виджет на месте, замкнут на публичные эндпоинты, CSRF выдан.
        $response->assertSee('id="scw-root"', false);
        $response->assertSee('data-post-url="'.route('chat.message').'"', false);
        $response->assertSee('data-history-url="'.route('chat.history').'"', false);
        $response->assertSee('data-csrf="', false);
        // Эмбед-режим: панель раскрыта кликом, пузырь/«Свернуть» спрятаны.
        $response->assertSee('scw-embed', false);
        $response->assertSee("getElementById('scw-toggle')", false);
        // Нет хрома кабинета: standalone <html> без навигации лэйаута.
        $response->assertSee('<!DOCTYPE html>', false);
        $response->assertDontSee('<nav', false);
    }

    public function test_csp_scopes_frame_ancestors_to_samskrtam_only(): void
    {
        config(['features.support_chat_embed' => true]);

        $response = $this->get(self::URL);

        $response->assertOk();
        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'Эмбед должен отдавать заголовок Content-Security-Policy.');
        $this->assertStringContainsString('frame-ancestors', $csp);
        $this->assertStringContainsString('samskrtam.ru', $csp);
        $this->assertNull($response->headers->get('X-Frame-Options'));

        // Заголовок не утекает на другие ответы чата — site-wide ничего не
        // ослабляется (инвариант как у course-interest embed, H5066).
        $history = $this->get('/chat/history');
        $this->assertNull($history->headers->get('Content-Security-Policy'));
    }

    public function test_page_param_is_passed_to_the_widget_for_greeting_and_telemetry(): void
    {
        config(['features.support_chat_embed' => true]);

        $response = $this->get(self::URL.'?page=https://samskrtam.ru/product/kurs-buhler');

        $response->assertOk();
        $response->assertSee('SCW_EMBED_PAGE', false);
        // @json экранирует слэши: "https:\/\/samskrtam.ru\/..."
        $response->assertSee('window.SCW_EMBED_PAGE = "https:\/\/samskrtam.ru\/product\/kurs-buhler";', false);
        // Приветствие каталога (паттерн H1198) сидит в клиентском JS виджета.
        $response->assertSee('Вопрос по этому товару', false);
    }

    public function test_page_param_is_sanitized(): void
    {
        config(['features.support_chat_embed' => true]);

        // Не-URL и не-http(s) схема → пустая строка (телеметрия живет адресом
        // iframe, как без параметра); javascript: не должен доехать до JS.
        $this->get(self::URL.'?page=javascript:alert(1)')
            ->assertOk()
            ->assertSee('window.SCW_EMBED_PAGE = "";', false);

        $this->get(self::URL.'?page=not%20a%20url')
            ->assertOk()
            ->assertSee('window.SCW_EMBED_PAGE = "";', false);

        // Невалидный UTF-8: @json на нестроке дал бы `= ;` и синтаксическую
        // ошибку в boot-скрипте (review H5451 P2) — должен отсекаться.
        $this->get(self::URL.'?page=https%3A%2F%2Fsamskrtam.ru%2F%FF%FE')
            ->assertOk()
            ->assertSee('window.SCW_EMBED_PAGE = "";', false);

        // >2048 символов и control-символы — тоже в пустоту.
        $long = 'https://samskrtam.ru/'.str_repeat('a', 2100);
        $this->get(self::URL.'?page='.urlencode($long))
            ->assertOk()
            ->assertSee('window.SCW_EMBED_PAGE = "";', false);
    }

    public function test_embed_response_is_not_cacheable(): void
    {
        config(['features.support_chat_embed' => true]);

        // Страница несет session-bound CSRF-токен: session-middleware уже
        // помечает ответ приватным/некэшируемым (no-cache, private) — это
        // и есть защита от edge-кеша (проверяем инвариант, не конкретную строку).
        $response = $this->get(self::URL);
        $cc = (string) $response->headers->get('Cache-Control');
        $this->assertTrue(
            str_contains($cc, 'no-store') || str_contains($cc, 'no-cache'),
            'Ответ эмбеда должен быть некэшируемым, получено: '.$cc
        );
    }
}
