<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketingSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Track C (H164, D10): sends via @zapisi_ORSbot's OWN token — unlike
 * SendTelegramChatMessageJob, which uses the main user-notification bot.
 */
class SendZapisiBotMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly string $chatId,
        public readonly string $text,
    ) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [15, 60, 300];
    }

    public function handle(): void
    {
        $token = (string) (MarketingSetting::cached()?->zapisi_bot_token ?? '');
        if ($token === '' || $this->chatId === '') {
            return;
        }

        $response = Http::post("https://api.telegram.org/bot{$token}/sendMessage", [
            'chat_id' => $this->chatId,
            'text' => $this->text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => true,
        ]);

        if (! $response->successful() || ! ($response->json('ok') ?? false)) {
            Log::warning('SendZapisiBotMessageJob: Telegram API error', [
                'chat_id' => $this->chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new \RuntimeException('Telegram sendMessage error: '.$response->body());
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::warning('SendZapisiBotMessageJob failed permanently', [
            'chat_id' => $this->chatId,
            'error' => $exception->getMessage(),
        ]);
    }
}
