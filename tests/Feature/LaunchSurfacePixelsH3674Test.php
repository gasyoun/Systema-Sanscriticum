<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\Auth\AdminLogin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * H3674 leftovers from Systema #2176 / H3651 visual QA (not a restyle).
 */
class LaunchSurfacePixelsH3674Test extends TestCase
{
    use RefreshDatabase;

    public function test_home_hero_h1_wraps_on_narrow_viewports(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('с преподавателем', $html);
        $this->assertMatchesRegularExpression(
            '/<h1[^>]*break-words[^>]*>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<h1[^>]*text-3xl[^>]*>/s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<h1[^>]*md:text-6xl[^>]*>/s',
            $html
        );
    }

    public function test_cookie_banner_sits_above_newsletter_and_reserves_space(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('cookie_consent_v1', $html);
        $this->assertStringContainsString('z-[10050]', $html);
        $this->assertStringContainsString('Принять все', $html);
        $this->assertStringContainsString('cookie_consent_v1', $html);
        $this->assertStringContainsString("localStorage.getItem('cookie_consent_v1')", $html);
    }

    public function test_newsletter_popup_waits_for_cookie_consent(): void
    {
        config()->set('features.newsletter_subscribe', true);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('Новости санскрита', $html);
        $this->assertStringContainsString("if (! localStorage.getItem('cookie_consent_v1'))", $html);
    }

    public function test_filament_admin_login_is_branded_ru_not_laravel_sign_in(): void
    {
        $html = $this->get('/admin/login')->assertOk()->getContent();

        $this->assertStringContainsString('Система Санскритикум', $html);
        $this->assertStringContainsString('Вход в панель школы', $html);
        $this->assertStringContainsString('Войти', $html);
        $this->assertStringContainsString('Электронная почта', $html);
        $this->assertStringContainsString('Пароль', $html);
        $this->assertStringContainsString('Запомнить меня', $html);
        $this->assertStringNotContainsString('Email address', $html);
        $this->assertStringNotContainsString('Forgot password', $html);
        $this->assertStringNotContainsString('>Sign in<', $html);
        $this->assertStringNotContainsString('>Laravel<', $html);
    }

    public function test_filament_admin_login_failed_attempt_is_russian(): void
    {
        Livewire::test(AdminLogin::class)
            ->fillForm([
                'email' => 'nobody@example.com',
                'password' => 'wrong-password',
            ])
            ->call('authenticate')
            ->assertHasFormErrors(['email' => 'Неверный email или пароль.']);
    }
}
