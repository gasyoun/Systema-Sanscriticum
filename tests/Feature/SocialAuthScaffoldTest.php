<?php

declare(strict_types=1);

namespace Tests\Feature;

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
    public function find_or_create_links_by_matching_email(): void
    {
        $user = User::factory()->create(['email' => 'match@example.test']);

        $result = app(SocialAuthService::class)->findOrCreateUser('vkontakte', '222', 'match@example.test', 'V');

        $this->assertTrue($result->is($user));
        $this->assertSame(1, User::count()); // нового не создали
        $this->assertDatabaseHas('social_accounts', [
            'user_id' => $user->id, 'provider' => 'vkontakte', 'provider_id' => '222',
        ]);
    }

    /** @test */
    public function find_or_create_makes_a_new_user_when_unknown(): void
    {
        $result = app(SocialAuthService::class)->findOrCreateUser('yandex', '333', 'new@example.test', 'Новый');

        $this->assertSame('new@example.test', $result->email);
        $this->assertDatabaseHas('social_accounts', ['provider' => 'yandex', 'provider_id' => '333']);
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
