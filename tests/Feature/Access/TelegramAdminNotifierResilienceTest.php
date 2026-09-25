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
                'cURL error 28: Operation timed out after 10000 milliseconds for https://api.telegram.org/bot'
                    .self::TOKEN.'/sendMessage'
            ),
        ]);

        $sent = app(TelegramAdminNotifier::class)->send(self::TOKEN, '555', '<b>алерт</b>');

        $this->assertFalse($sent, 'send() обязан вернуть false, а не бросить исключение');
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertStringContainsString('отправка не удалась', $message);
            $this->assertSame('555', $context['chat_id']);

            // Токен бота приезжает в тексте cURL-ошибки внутри URL — в лог нельзя.
            $this->assertStringNotContainsString(self::TOKEN, (string) $context['error']);
            $this->assertDoesNotMatchRegularExpression('/bot\d+:/', (string) $context['error']);

            // В контексте — длина алерта, а не сам текст (в нём email студента).
            $this->assertArrayNotHasKey('text', $context);
            $this->assertSame(mb_strlen('<b>алерт</b>'), $context['text_length']);

            return true;
        });
    }

    public function test_notify_admins_returns_no_recipients_and_stops_after_the_first_network_failure(): void
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
        $this->assertSame(
            1,
            $attempts,
            'сетевой сбой прерывает цикл: api.telegram.org один на всех, иначе 2 админа × 5 с = те же 10 с'
        );
    }

    public function test_http_error_does_not_stop_the_loop_for_other_recipients(): void
    {
        config([
            'services.telegram.bot_token' => self::TOKEN,
            'services.telegram.admin_id' => '555,777',
        ]);

        // Не-2xx — не сетевой сбой: второму админу по-прежнему пробуем доставить.
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400),
        ]);

        $delivered = app(TelegramAdminNotifier::class)->notifyAdmins('алерт');

        $this->assertSame([], $delivered);
        Http::assertSentCount(2);
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

    public function test_error_body_is_sanitized_before_logging(): void
    {
        Log::spy();
        Http::fake([
            'api.telegram.org/*' => Http::response(
                'Bad Request for https://api.telegram.org/bot'.self::TOKEN.'/sendMessage',
                400
            ),
        ]);

        $sent = app(TelegramAdminNotifier::class)->send(self::TOKEN, '555', 'алерт');

        $this->assertFalse($sent);
        Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context): bool {
            $this->assertStringNotContainsString(self::TOKEN, (string) $context['body']);
            $this->assertDoesNotMatchRegularExpression('/bot\d+:/', (string) $context['body']);

            return true;
        });
    }
}
