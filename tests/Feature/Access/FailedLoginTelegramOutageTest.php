<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Models\AccessAttempt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Прод-инцидент 25-09-2026: сеть до api.telegram.org недоступна (DNS отдаёт
 * недоступные IP), и каждый неудачный вход отвечал 500 вместо «неверный
 * пароль» — алерт админам ходил синхронно из LogFailedAuthentication.
 * Недоступность Telegram не имеет права менять HTTP-ответ пользователю.
 */
class FailedLoginTelegramOutageTest extends TestCase
{
    use RefreshDatabase;

    /** Сколько раз код реально пытался сходить в недоступный Telegram. */
    private int $telegramAttempts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.telegram.bot_token' => '123456:SECRET',
            'services.telegram.admin_id' => '555',
        ]);

        $this->telegramAttempts = 0;

        // Считаем попытки счётчиком: бросающий стаб Http::fake в журнал
        // запросов не попадает, а нам нужно доказать, что путь алерта
        // действительно исполнялся внутри запроса логина.
        Http::fake([
            'api.telegram.org/*' => function () {
                $this->telegramAttempts++;

                throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
            },
        ]);
    }

    public function test_wrong_password_redirects_with_validation_error_while_telegram_is_down(): void
    {
        $user = User::factory()->create(['email' => 'stuck@example.com']);

        $response = $this->from(route('login'))->post(route('login.post'), [
            'email' => $user->email,
            'password' => 'wrong-pass',
        ]);

        $response->assertRedirect(route('login'));   // 302, а не 500
        $response->assertSessionHasErrors('email');
        $this->assertSame(1, AccessAttempt::query()->count());
    }

    public function test_alert_triggering_failed_login_does_not_500_while_telegram_is_down(): void
    {
        $user = User::factory()->create(['email' => 'stuck@example.com']);

        // Третий подряд неудачный вход по одному email = «застрял»: на нём
        // AccessAttemptLogger реально зовёт алерт админам — именно здесь
        // раньше падал запрос.
        foreach (range(1, 3) as $attempt) {
            $response = $this->from(route('login'))->post(route('login.post'), [
                'email' => $user->email,
                'password' => 'wrong-pass',
            ]);

            $this->assertNotSame(
                500,
                $response->getStatusCode(),
                "попытка #{$attempt} ответила 500 при недоступном Telegram"
            );
            $response->assertRedirect(route('login'));
        }

        $this->assertSame(
            3,
            AccessAttempt::query()->where('kind', AccessAttempt::KIND_FAILED_LOGIN)->count(),
            'неудачные входы должны по-прежнему писаться в ленту'
        );
        $this->assertSame(
            1,
            $this->telegramAttempts,
            'алерт админам должен уйти ровно один раз (на третьем подряд провале) и не уронить запрос'
        );
    }
}
