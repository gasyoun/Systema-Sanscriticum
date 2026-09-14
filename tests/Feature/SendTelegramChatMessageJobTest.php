<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendTelegramChatMessageJob;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

/**
 * Зеркало идемпотентности для основного бота (SendZapisiBotMessageJobTest
 * покрывает сценарии подробно; здесь — ключевые случаи).
 */
class SendTelegramChatMessageJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.telegram.bot_token' => 'MAIN-TOKEN']);
    }

    public function test_retry_of_delivered_message_is_suppressed_no_second_copy(): void
    {
        Redis::shouldReceive('set')->twice()->andReturn(true, false);

        Http::fake(['*' => Http::response(['ok' => true])]);

        $job = new SendTelegramChatMessageJob('-100456', 'текст');

        $job->handle();
        $job->handle();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'botMAIN-TOKEN/sendMessage'));
    }

    public function test_telegram_error_response_releases_claim_and_throws(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('del')->once();

        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Bad Request'], 400)]);

        $this->expectException(\RuntimeException::class);

        (new SendTelegramChatMessageJob('-100456', 'текст'))->handle();
    }

    public function test_timeout_after_send_keeps_claim_and_does_not_rethrow(): void
    {
        Redis::shouldReceive('set')->once()->andReturn(true);
        Redis::shouldReceive('del')->never();

        Http::fake(fn (): Response => throw new ConnectException(
            'cURL error 28: Operation timed out',
            new \GuzzleHttp\Psr7\Request('POST', 'https://api.telegram.org'),
        ));

        (new SendTelegramChatMessageJob('-100456', 'текст'))->handle();

        Http::assertSentCount(1);
    }
}
