<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProcessTelegramMagnetUpdate implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly array $update,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        $message = $this->update['message'] ?? null;
        if (! $message) {
            return;
        }

        $text = $message['text'] ?? '';
        $chatId = $message['chat']['id'] ?? null;

        if (! $chatId) {
            return;
        }

        if (! str_starts_with($text, '/start')) {
            return;
        }

        $parts = explode(' ', $text, 2);
        $token = $parts[1] ?? null;

        if (! $token) {
            // /start без токена — юзер нашёл бота сам, не через форму.
            Log::info('Telegram /start without token', ['chat_id' => $chatId]);

            return;
        }

        $lead = Lead::where('magnet_token', $token)->first();

        if (! $lead) {
            Log::warning('Telegram: unknown magnet_token', ['token' => $token, 'chat_id' => $chatId]);

            return;
        }

        if (! $lead->telegram_chat_id) {
            $lead->update(['telegram_chat_id' => $chatId]);
        }

        SendLeadMagnet::dispatch($lead->id);
    }
}
