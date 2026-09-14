<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\MarketingSetting;
use App\Support\TelegramSendGuard;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Идемпотентность отправки (TelegramSendGuard): ретрай джоба после потерянного
 * ответа Telegram не должен давать вторую одинаковую копию в чате.
 */
class SendZapisiBotMessageJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MarketingSetting::create(['zapisi_bot_token' => 'ZAPISI-TOKEN']);
        Cache::forget('marketing_setting.singleton');
    }

    public function test_retry_of_delivered_message_is_suppressed_no_second_copy(): void
    {
        // Первая попытка: claim взят, отправка успешна. Вторая (ретрай): claim
        // отклонён — повторной отправки быть не должно.
        Redis::shouldReceive('set')->twice()->andReturn(true, false);

        Http::fake(['*' => Http::response(['ok' => true])]);

        (new SendZapisiBotMessageJob('-100123', '<b>Скоро занятие</b>'))->handle();
        (new SendZapisiBotMessageJob('-100123', '<b>Скоро занятие</b>'))->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botZAPISI-TOKEN/sendMessage'));
    }

    public function test_telegram_error_response_releases_claim_so_backoff_retry_can_send(): void
    {
        // Попытка 1: claim взят, Telegram ответил отказом → ключ отпущен, исключение.
        // Попытка 2 (ретрай): claim снова берётся, отправка проходит.
        Redis::shouldReceive('set')->twice()->andReturn(true, true);
        Redis::shouldReceive('del')->once();

        Http::fake([
            'api.telegram.org/*' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Bad Request: chat not found'], 400)
                ->push(['ok' => true]),
        ]);

        $job = new SendZapisiBotMessageJob('-100123', 'текст');

        try {
            $job->handle();
            $this->fail('Ожидался RuntimeException после отказа Telegram.');
        } catch (\RuntimeException) {
        }

        $job->handle();

        Http::assertSentCount(2);
    }

    public function test_timeout_after_send_keeps_claim_and_does_not_rethrow(): void
    {
        // Доставка под вопросом: ключ держим, ретрай подавится — дубль невозможен.
        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('del')->never();

        Http::fake(fn (): Response => throw new ConnectException(
            'cURL error 28: Operation timed out',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.telegram.org'),
        ));

        (new SendZapisiBotMessageJob('-100123', 'текст'))->handle();

        Http::assertSentCount(1);
    }

    public function test_connect_failure_also_keeps_claim_and_does_not_rethrow(): void
    {
        // Guzzle/Laravel сообщают «не соединились» тем же классом исключения,
        // что и таймаут после отправки, — контракт единый: ключ держим.
        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('del')->never();

        Http::fake(fn (): Response => throw new ConnectException(
            'cURL error 7: Failed to connect',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.telegram.org'),
        ));

        (new SendZapisiBotMessageJob('-100123', 'текст'))->handle();

        Http::assertSentCount(1);
    }

    public function test_dedup_key_is_stable_per_chat_and_text(): void
    {
        $this->assertSame(
            TelegramSendGuard::key('-100123', 'а'),
            TelegramSendGuard::key('-100123', 'а'),
        );
        $this->assertNotSame(
            TelegramSendGuard::key('-100123', 'а'),
            TelegramSendGuard::key('-100124', 'а'),
        );
        $this->assertNotSame(
            TelegramSendGuard::key('-100123', 'а'),
            TelegramSendGuard::key('-100123', 'б'),
        );
    }
}
