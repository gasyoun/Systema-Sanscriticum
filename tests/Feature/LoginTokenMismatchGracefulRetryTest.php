<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

/**
 * H1765 — /login and /shop/login used to fall through the checkout-only
 * TokenMismatchException handling straight to Laravel's raw "419 Page
 * Expired" dead end (app/Exceptions/Handler.php only special-cased
 * payment.create/checkout.*). Access-log evidence: real students hit this
 * on POST /login and /shop/login throughout 27-07-2026, unrelated to any
 * deploy that day.
 *
 * Laravel's VerifyCsrfToken skips verification entirely during unit tests
 * (runningUnitTests()), so a plain $this->post() never reproduces a real
 * mismatch. Each test here rebinds the middleware with that bypass forced
 * off, so a request with no/garbage _token throws the real exception and
 * exercises the actual renderable registered in Handler::register().
 */
class LoginTokenMismatchGracefulRetryTest extends TestCase
{
    private function forceRealCsrfVerification(): void
    {
        $this->app->bind(VerifyCsrfToken::class, function (Application $app) {
            return new class($app, $app->make(Encrypter::class)) extends VerifyCsrfToken
            {
                protected function runningUnitTests()
                {
                    return false;
                }
            };
        });
    }

    /** @test */
    public function login_token_mismatch_redirects_back_with_a_friendly_message_instead_of_a_dead_end(): void
    {
        $this->forceRealCsrfVerification();

        $response = $this->from(route('login'))->post(route('login.post'), [
            'email' => 'student@example.com',
            'password' => 'wrongpassword',
            // no _token — guaranteed mismatch
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('error', 'Сессия обновилась — введите данные ещё раз.');
    }

    /** @test */
    public function shop_login_token_mismatch_returns_json_asking_to_reload_not_a_redirect(): void
    {
        $this->forceRealCsrfVerification();

        $response = $this->postJson(route('shop.login'), [
            'email' => 'student@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(419);
        $response->assertJson([
            'success' => false,
        ]);
        $this->assertStringContainsString('обновите страницу', $response->json('message'));
    }
}
