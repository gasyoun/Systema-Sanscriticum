<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LandingBot;
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
        public readonly ?int $landingBotId = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        // Per-bot режим: форвардим ЛЮБОЙ апдейт в n8n (анкета/прогрев), включая
        // callback_query (у него нет ['message'], поэтому делаем это до раннего return).
        if ($this->landingBotId !== null) {
            $bot = LandingBot::find($this->landingBotId);
            if ($bot && ! empty($bot->n8n_forward_url)) {
                ForwardUpdateToN8n::dispatch($bot->n8n_forward_url, $this->update);
            }
        }

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

        SendLeadMagnet::dispatch($lead->id, 'telegram');
    }
}
