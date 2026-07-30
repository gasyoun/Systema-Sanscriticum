<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * H1949 — password /login exposes an opt-in «Запомнить меня» long-lived cookie.
 * shopLogin() already passed remember; the main form did not. Default remains off
 * when the checkbox is absent so an unattended browser does not stay signed in.
 */
class LoginRememberMeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function login_page_renders_the_remember_me_checkbox(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="remember"', false)
            ->assertSee('Запомнить меня', false);
    }

    /** @test */
    public function password_login_without_remember_does_not_set_a_remember_token(): void
    {
        $user = User::factory()->create([
            'email' => 'student@example.com',
            'password' => Hash::make('secret123'),
            'remember_token' => null,
        ]);

        $this->post(route('login.post'), [
            'email' => 'student@example.com',
            'password' => 'secret123',
        ])->assertRedirect();

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->remember_token);
    }

    /** @test */
    public function password_login_with_remember_sets_a_remember_token(): void
    {
        $user = User::factory()->create([
            'email' => 'student@example.com',
            'password' => Hash::make('secret123'),
            'remember_token' => null,
        ]);

        $response = $this->post(route('login.post'), [
            'email' => 'student@example.com',
            'password' => 'secret123',
            'remember' => '1',
        ]);

        $response->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $token = $user->fresh()->remember_token;
        $this->assertNotNull($token);
        $this->assertNotSame('', $token);

        // Laravel queues the long-lived recaller cookie when remember=true.
        $cookieNames = collect($response->headers->getCookies())->map->getName();
        $this->assertTrue(
            $cookieNames->contains(fn (string $name) => str_starts_with($name, 'remember_web_')),
            'expected a remember_web_* cookie when remember is checked; got: '.$cookieNames->implode(', ')
        );
    }

    /** @test */
    public function password_login_rejects_bad_credentials_even_with_remember(): void
    {
        User::factory()->create([
            'email' => 'student@example.com',
            'password' => Hash::make('secret123'),
        ]);

        $this->from(route('login'))
            ->post(route('login.post'), [
                'email' => 'student@example.com',
                'password' => 'wrong-password',
                'remember' => '1',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
