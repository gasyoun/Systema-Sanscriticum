<?php

declare(strict_types=1);

namespace Tests\Feature\Bot;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Прод-инцидент 25-09-2026: пока вызовы Bot API из тела вебхука бросали
 * исключение, недоступный Telegram получал 500 на POST /api/telegram/webhook,
 * повторял апдейт — и каждый повтор снова держал FPM-воркер (до 10 с по
 * дефолтам клиента). Самоподдерживающаяся петля на ровном месте.
 *
 * Контракт после фикса: апдейт обрабатывается и отвечает 200 независимо от
 * доступности Telegram, а рассылка алерта админам не умножает таймаут на их
 * число.
 */
class TelegramWebhookOutageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = '123456:SECRET';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('services.telegram.bot_webhook_secret', 'test-tg');
        config()->set('services.telegram.bot_token', self::TOKEN);
        config()->set('services.telegram.admin_id', '555,777');

        // Вебхук fail-closed: без секрета Telegram-апдейты не принимаются.
        $this->withHeader('X-Telegram-Bot-Api-Secret-Token', 'test-tg');
    }

    /** Считаем попытки сами: бросающий стаб не попадает в журнал запросов. */
    private function telegramDown(int &$attempts): void
    {
        Http::fake([
            'api.telegram.org/*' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException(
                    'cURL error 28: Operation timed out after 10000 milliseconds for '
                    .'https://api.telegram.org/bot'.self::TOKEN.'/sendMessage'
                );
            },
            '*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_reply_to_unsubscribe_survives_telegram_outage(): void
    {
        $attempts = 0;
        $this->telegramDown($attempts);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'message_id' => 1,
                'chat' => ['id' => 991001, 'type' => 'private'],
                'from' => ['id' => 991001],
                'text' => 'отписаться',
            ],
        ])->assertOk();

        $this->assertSame(
            1,
            $attempts,
            'ответ бота пытались отправить один раз; сетевой сбой — не повод отвечать Telegram 500 и повторять апдейт'
        );
    }

    public function test_callback_query_survives_telegram_outage(): void
    {
        $attempts = 0;
        $this->telegramDown($attempts);

        $this->postJson('/api/telegram/webhook', [
            'callback_query' => [
                'id' => 'cb-outage-1',
                'data' => 'unknown:payload',
                'from' => ['id' => 991002],
                'message' => ['chat' => ['id' => 991002, 'type' => 'private']],
            ],
        ])->assertOk();

        $this->assertSame(1, $attempts, 'answerCallbackQuery — косметика: её провал не имеет права ронять вебхук');
    }

    public function test_admin_alert_loop_stops_after_the_first_network_failure(): void
    {
        $chatId = 991003;
        User::factory()->create(['telegram_id' => $chatId, 'name' => 'Студент']);

        // Режим человека: сообщение студента уходит алертом всем админам.
        Cache::put("chat_human_{$chatId}", true, 7200);

        $attempts = 0;
        $this->telegramDown($attempts);

        $this->postJson('/api/telegram/webhook', [
            'message' => [
                'message_id' => 2,
                'chat' => ['id' => $chatId, 'type' => 'private'],
                'from' => ['id' => $chatId],
                'text' => 'подскажите, пожалуйста, по домашнему заданию',
            ],
        ])->assertOk();

        $this->assertSame(
            1,
            $attempts,
            'админов двое, но api.telegram.org один: сетевой сбой обязан прерывать цикл, а не умножать таймаут'
        );
    }
}
