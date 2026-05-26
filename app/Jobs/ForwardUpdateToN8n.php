<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Форвардит сырой Telegram-апдейт в ноду Webhook n8n (анкета + прогрев).
 *
 * Laravel владеет webhook бота лендинга и перехватывает только выдачу магнита
 * (/start <token>); всё разговорное (ответы анкеты, callback-кнопки) уходит
 * сюда, чтобы n8n вёл FSM. Отправка ответов из n8n идёт нодами sendMessage
 * по токену того же бота — владение webhook для этого не требуется.
 */
final class ForwardUpdateToN8n implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $url,
        public readonly array $update,
    ) {
        $this->onQueue('webhooks');
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(): void
    {
        if ($this->url === '') {
            return;
        }

        $response = Http::timeout(10)->post($this->url, $this->update);

        if (! $response->successful()) {
            Log::warning('ForwardUpdateToN8n: n8n webhook error', [
                'url' => $this->url,
                'status' => $response->status(),
            ]);
            throw new RuntimeException('n8n forward error: HTTP '.$response->status());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('ForwardUpdateToN8n failed permanently', [
            'url' => $this->url,
            'error' => $exception->getMessage(),
        ]);
    }
}
