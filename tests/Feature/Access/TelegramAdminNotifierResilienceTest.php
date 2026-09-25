<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * TelegramAdminNotifier::send() ходит в сеть СИНХРОННО внутри запроса логина:
 * неудачный вход → LogFailedAuthentication → AccessAttemptLogger::record() →
 * maybeAlert() → notifyAdmins(). Пока исключение соединения летело наружу,
 * недоступность api.telegram.org превращала «неверный пароль» в 500 и держала
 * FPM-воркер ~10 с на каждую попытку (прод, 25-09-2026).
 */
class TelegramAdminNotifierResilienceTest extends TestCase
{
    private const TOKEN = '123456:SECRET';

    public function test_connection_failure_is_swallowed_and_reported_as_false(): void
    {
        Log::spy();
        Http::fake([
            'api.telegram.org/*' => fn () => throw new ConnectionException(
                'cURL error 28: Operation timed out after 10000 milliseconds'
            ),
        ]);

        $sent = app(TelegramAdminNotifier::class)->send(self::TOKEN, '555', '<b>алерт</b>');

        $this->assertFalse($sent, 'send() обязан вернуть false, а не бросить исключение');
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'отправка не удалась')
                && $context['chat_id'] === '555'
        );
    }

    public function test_notify_admins_returns_no_recipients_instead_of_throwing(): void
    {
        config([
            'services.telegram.bot_token' => self::TOKEN,
            'services.telegram.admin_id' => '555,777',
        ]);

        // Бросающий стаб Http::fake не попадает в журнал запросов (запись не
        // успевает произойти), поэтому считаем попытки сами.
        $attempts = 0;
        Http::fake([
            'api.telegram.org/*' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('cURL error 28: Operation timed out after 10000 milliseconds');
            },
        ]);

        $delivered = app(TelegramAdminNotifier::class)->notifyAdmins('алерт');

        $this->assertSame([], $delivered, 'никому не ушло — и не упало');
        $this->assertSame(2, $attempts, 'оба адреса попробовали, оба не дошли');
    }

    public function test_successful_send_still_returns_true(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true], 200),
        ]);

        $sent = app(TelegramAdminNotifier::class)->send(self::TOKEN, '555', 'алерт');

        $this->assertTrue($sent);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/bot'.self::TOKEN.'/sendMessage'));
    }

    public function test_telegram_error_status_still_logs_error_and_returns_false(): void
    {
        Log::spy();
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400),
        ]);

        $sent = app(TelegramAdminNotifier::class)->send(self::TOKEN, '555', 'алерт');

        $this->assertFalse($sent);
        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context): bool => str_contains($message, 'admin notifier error')
                && $context['status'] === 400
        );
    }
}
