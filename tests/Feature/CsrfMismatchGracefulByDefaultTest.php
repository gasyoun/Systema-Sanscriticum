<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

/**
 * H1771 — H1765 убрало голую страницу «419 Page Expired», но убрало её
 * ПОИМЁННО: три ветви по routeIs() и `return null` для всего остального.
 * В routes/web.php 40 POST-маршрутов, покрыто было шесть; восстановление
 * пароля, регистрация, сдача домашки и прочие по-прежнему упирались в
 * страницу без выхода. Хрупкость — сам allowlist: симптом возвращается при
 * каждом новом POST-маршруте, добавленном без мысли о CSRF (ровно так ветка
 * чекаута и пролежала мёртвой пять недель).
 *
 * Теперь graceful — это ДЕФОЛТ, а форма ответа выбирается по вызывающему
 * (expectsJson()), не по имени маршрута; три прежние ветви понижены до
 * переопределения текста. Здесь закреплены: незанесённый в список маршрут в
 * обоих обличьях, «переопределение — только текст», и единственное
 * сознательное исключение — Livewire.
 *
 * Как и в LoginTokenMismatchGracefulRetryTest, VerifyCsrfToken под тестами
 * пропускает проверку (runningUnitTests()), поэтому middleware
 * перепривязывается с выключенным байпасом — иначе настоящий mismatch не
 * воспроизвести.
 */
class CsrfMismatchGracefulByDefaultTest extends TestCase
{
    /** Текст по умолчанию для обычного сабмита формы. */
    private const DEFAULT_HTML = 'Сессия обновилась — отправьте форму ещё раз.';

    /** Текст по умолчанию для fetch/XHR: страница сама не перезагрузится. */
    private const DEFAULT_JSON = 'Сессия обновилась — обновите страницу (F5) и попробуйте ещё раз.';

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

    /**
     * POST /forgot-password (password.email) — настоящий маршрут, которого в
     * прежнем allowlist не было и который до H1771 отдавал голый 419.
     *
     * @test
     */
    public function an_unlisted_web_post_redirects_back_with_a_flash_instead_of_the_bare_419_page(): void
    {
        $this->forceRealCsrfVerification();

        $response = $this->from(route('password.request'))->post(route('password.email'), [
            'email' => 'student@example.com',
            // no _token — guaranteed mismatch
        ]);

        $response->assertRedirect(route('password.request'));
        $response->assertSessionHas('error', self::DEFAULT_HTML);
    }

    /**
     * Тот же маршрут, вызванный как JSON, получает 419 с {success,message} —
     * тот же контракт, что у AuthController::shopLogin(), а не второй.
     *
     * @test
     */
    public function the_same_unlisted_route_answers_json_callers_with_419_json(): void
    {
        $this->forceRealCsrfVerification();

        $response = $this->postJson(route('password.email'), [
            'email' => 'student@example.com',
        ]);

        $response->assertStatus(419);
        $response->assertJson([
            'success' => false,
            'message' => self::DEFAULT_JSON,
        ]);
    }

    /**
     * Прежние ветви понижены до переопределения ТЕКСТА: форма ответа теперь
     * следует за вызывающим. login.post, позванный как JSON, обязан получить
     * JSON — со своим сообщением, а не с общим.
     *
     * @test
     */
    public function a_route_override_changes_only_the_message_not_the_response_shape(): void
    {
        $this->forceRealCsrfVerification();

        $response = $this->postJson(route('login.post'), [
            'email' => 'student@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(419);
        $response->assertJson([
            'success' => false,
            'message' => 'Сессия обновилась — введите данные ещё раз.',
        ]);
    }

    /**
     * Единственное исключение из graceful-дефолта.
     *
     * Обе панели Filament работают через дефолтный POST /livewire/update
     * (->middleware('web')), поэтому их протухший токен приходит СЮДА же.
     * Клиент Livewire не шлёт ни Accept, ни X-Requested-With — expectsJson()
     * на нём ложен, и по умолчанию он получил бы 302. Но у Livewire есть свой
     * обработчик, завязанный на голый статус (`response.status === 419` →
     * handlePageExpiry()); redirect его обходит и роняет несохранённую форму
     * админа молча. Такие запросы обязаны уходить в дефолт Laravel нетронутыми.
     *
     * @test
     */
    public function livewire_requests_are_left_to_livewires_own_page_expiry_path(): void
    {
        $this->forceRealCsrfVerification();

        $response = $this->withHeaders(['X-Livewire' => ''])
            ->post('/livewire/update', ['components' => []]);

        $response->assertStatus(419);
        $this->assertFalse($response->isRedirect(), 'Livewire must not be redirected — it needs the bare 419.');
        $response->assertSessionMissing('error');
    }
}
