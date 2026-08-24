<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LandingBot;
use App\Models\Lead;
use App\Services\Messaging\DeliveryChannelManager;
use App\Services\Messaging\TelegramDeliveryChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Доставляет статус листа ожидания связанному гостю (H3339) — заявившемуся
 * на лендинге с status_block, который привязал чат боту через deep-link.
 * Файл не доставляется никогда: это чисто текстовая подписка.
 */
final class SendWaitlistGuestStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $leadId,
        public readonly string $text,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(DeliveryChannelManager $channels): void
    {
        $lead = Lead::with('landingPage.bot')->find($this->leadId);

        if (! $lead) {
            Log::warning("SendWaitlistGuestStatus: Lead #{$this->leadId} not found");

            return;
        }

        // Один лид — одно сообщение: предпочитаем канал, где чат уже привязан.
        [$channelName, $userId] = match (true) {
            filled($lead->telegram_chat_id) => ['telegram', (string) $lead->telegram_chat_id],
            filled($lead->vk_user_id) => ['vk', (string) $lead->vk_user_id],
            filled($lead->max_user_id) => ['max', (string) $lead->max_user_id],
            default => [null, null],
        };

        if ($channelName === null || blank($userId)) {
            Log::info("SendWaitlistGuestStatus: Lead #{$this->leadId} has no bound chat, skip");

            return;
        }

        $channel = $channels->get($channelName);

        // «Свой бот на лендинг»: пишем тем ботом, с которым юзер разговаривает.
        if ($channelName === 'telegram' && $channel instanceof TelegramDeliveryChannel) {
            $bot = $lead->landingPage?->bot;
            if ($bot && $bot instanceof LandingBot && $bot->isUsable()) {
                $channel = $channel->usingCredentials($bot->tg_bot_token, $bot->tg_bot_username);
            }
        }

        $channel->sendMessage($userId, $this->text);

        Log::info("SendWaitlistGuestStatus: delivered to Lead #{$this->leadId} via {$channelName}");
    }

    public function failed(Throwable $e): void
    {
        Log::error("SendWaitlistGuestStatus: FAILED for Lead #{$this->leadId}", [
            'error' => $e->getMessage(),
        ]);
    }
}
