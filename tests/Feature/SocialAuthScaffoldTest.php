<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\SocialEmailNotVerifiedException;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\SocialAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SocialAuthScaffoldTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function find_or_create_returns_user_of_existing_social_account(): void
    {
        $user = User::factory()->create();
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => '111']);

        $result = app(SocialAuthService::class)->findOrCreateUser('google', '111', 'x@example.test', 'X');

        $this->assertTrue($result->is($user));
        $this->assertSame(1, SocialAccount::count());
        $this->assertSame(1, User::count());
    }

    /** @test */
    public function find_or_create_links_by_matching_email_when_verified(): void
    {
        $user = User::factory()->create(['email' => 'match@example.test']);

        // Подтверждённый провайдером email — линкуем к существующему аккаунту.
        $result = app(SocialAuthService::class)
            ->findOrCreateUser('google', '222', 'match@example.test', 'V', emailVerified: true);

        $this->assertTrue($result->is($user));
        $this->assertSame(1, User::count()); // нового не создали
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id, 'provider' => 'google', 'provider_id' => '222',
        ]);
    }

    /** @test */
    public function find_or_create_rejects_unverified_email_of_existing_user(): void
    {
        User::factory()->create(['email' => 'match@example.test']);

        // Неподтверждённый email, совпавший с чужим аккаунтом = takeover → отказ.
        $this->expectException(SocialEmailNotVerifiedException::class);

        app(SocialAuthService::class)
            ->findOrCreateUser('vkontakte', '222', 'match@example.test', 'V', emailVerified: false);
    }

    /** @test */
    public function find_or_create_makes_a_new_user_with_verified_email(): void
    {
        $result = app(SocialAuthService::class)
            ->findOrCreateUser('google', '333', 'new@example.test', 'Новый', emailVerified: true);

        $this->assertSame('new@example.test', $result->email);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'google', 'provider_id' => '333']);
    }

    /** @test */
    public function find_or_create_new_user_with_unverified_email_uses_stub(): void
    {
        // Неподтверждённый email нового пользователя НЕ занимает пространство логинов:
        // в users кладём stub, реальный email — только на строке social_account.
        $result = app(SocialAuthService::class)
            ->findOrCreateUser('yandex', '444', 'squat@example.test', 'Аноним', emailVerified: false);

        $this->assertSame('yandex_444@social.local', $result->email);
        $this->assertDatabaseHas('social_accounts', [
            'provider' => 'yandex', 'provider_id' => '444', 'email' => 'squat@example.test',
        ]);
    }

    /** @test */
    public function enabled_providers_reflect_configured_client_ids(): void
    {
        config(['services.google.client_id' => 'abc', 'services.vkontakte.client_id' => null, 'services.yandex.client_id' => null]);

        $this->assertSame(['google'], SocialAuthService::enabledProviders());
        $this->assertTrue(SocialAuthService::isEnabled('google'));
        $this->assertFalse(SocialAuthService::isEnabled('vkontakte'));
    }

    /** @test */
    public function redirect_for_disabled_provider_is_404(): void
    {
        config(['services.google.client_id' => null]);

        $this->get('/auth/google/redirect')->assertNotFound();
    }

    /** @test */
    public function login_page_shows_button_only_when_provider_enabled(): void
    {
        config(['services.google.client_id' => null, 'services.vkontakte.client_id' => null, 'services.yandex.client_id' => null]);
        $this->get('/login')->assertOk()->assertDontSee('войдите через');

        config(['services.google.client_id' => 'abc']);
        $this->get('/login')->assertOk()->assertSee('войдите через')->assertSee('Google');
    }
}
