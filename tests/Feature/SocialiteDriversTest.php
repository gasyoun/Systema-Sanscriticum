<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Community-драйверы Socialite (VK/Yandex) зарегистрированы через
 * SocialiteWasCalled-listener: при заданных кредах /auth/{provider}/redirect
 * строит OAuth-URL провайдера (драйвер резолвится без ошибки).
 */
class SocialiteDriversTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function vkontakte_driver_resolves_and_redirects_to_oauth(): void
    {
        config([
            'services.vkontakte.client_id' => 'vk-id',
            'services.vkontakte.client_secret' => 'vk-secret',
            'services.vkontakte.redirect' => 'http://localhost/auth/vkontakte/callback',
        ]);

        $resp = $this->get('/auth/vkontakte/redirect');

        $resp->assertRedirect();
        // VK 5.x использует oauth.vk.ru (новый домен VK ID).
        $this->assertStringContainsString('oauth.vk.ru', $resp->headers->get('Location'));
        $this->assertStringContainsString('vk-id', $resp->headers->get('Location'));
    }

    /** @test */
    public function yandex_driver_resolves_and_redirects_to_oauth(): void
    {
        config([
            'services.yandex.client_id' => 'ya-id',
            'services.yandex.client_secret' => 'ya-secret',
            'services.yandex.redirect' => 'http://localhost/auth/yandex/callback',
        ]);

        $resp = $this->get('/auth/yandex/redirect');

        $resp->assertRedirect();
        $this->assertStringContainsString('yandex', $resp->headers->get('Location'));
        $this->assertStringContainsString('ya-id', $resp->headers->get('Location'));
    }

    /** @test */
    public function disabled_provider_without_credentials_is_404(): void
    {
        config(['services.yandex.client_id' => null]);

        $this->get('/auth/yandex/redirect')->assertNotFound();
    }
}
