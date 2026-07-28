<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * H1765 — /login and /shop/login used to fall through the checkout-only
 * TokenMismatchException handling straight to Laravel's raw "419 Page
 * Expired" dead end (app/Exceptions/Handler.php only special-cased
 * payment.create/checkout.*). Access-log evidence: real students hit this
 * on POST /login and /shop/login throughout 27-07-2026, unrelated to any
 * deploy that day.
 *
 * The checkout branch itself turned out to be dead code as well. Laravel's
 * Handler::render() runs prepareException() BEFORE renderViaCallbacks(), and
 * prepareException() replaces TokenMismatchException with
 * HttpException(419, ..., previous: $e) — so a renderable() callback
 * type-hinted on TokenMismatchException is never invoked at all. The handler
 * therefore matches the wrapper (419 + previous instanceof
 * TokenMismatchException), and checkout is covered here too so the branch
 * cannot silently die again.
 *
 * Laravel's VerifyCsrfToken also skips verification entirely during unit
 * tests (runningUnitTests()), so a plain $this->post() never reproduces a
 * real mismatch. Each test here rebinds the middleware with that bypass
 * forced off, so a request with no/garbage _token throws the real exception
 * and exercises the actual renderable registered in Handler::register().
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
        $this->assertStringContainsString('попробуйте войти ещё раз', $response->json('message'));
    }

    /**
     * The pre-existing checkout branch (295ea8b8, 25-06-2026) shipped without
     * a test and never ran — it is resurrected by the same fix, so it gets a
     * regression test of its own.
     *
     * @test
     */
    public function checkout_token_mismatch_still_gets_its_own_message(): void
    {
        $this->forceRealCsrfVerification();

        $response = $this->from('/checkout/1')->post(route('payment.create'), [
            'email' => 'student@example.com',
        ]);

        $response->assertRedirect('/checkout/1');
        $response->assertSessionHas('error', 'Сессия обновилась — нажмите «К безопасной оплате» ещё раз.');
    }

    /**
     * The handler now type-hints HttpException (the wrapper), which is a far
     * wider net than TokenMismatchException — this pins that it still declines
     * everything that is not a CSRF mismatch.
     *
     * @test
     */
    public function other_http_exceptions_are_left_to_the_default_handler(): void
    {
        // Two segments on purpose: the /{slug} landing-page catch-all in
        // routes/web.php would otherwise swallow a single-segment test route.
        Route::get('/h1765-guard/teapot', fn () => abort(418));
        Route::get('/h1765-guard/bare-419', fn () => abort(419, 'Page Expired'));

        $this->get('/h1765-guard/teapot')->assertStatus(418);

        // 419 with no TokenMismatchException behind it is not ours either.
        $this->get('/h1765-guard/bare-419')->assertStatus(419);
    }
}
