<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Models\LeadNote;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Отправляет лиду произвольное текстовое сообщение в выбранные мессенджеры
 * (TG/VK/Max) по его собранным id и пишет запись в историю общения.
 * Аналог SendMessengerAlerts, но для Lead (у лидов нет аккаунта User).
 */
final class SendLeadMessenger implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  list<string>  $channels  Имена каналов: telegram|vk|max.
     */
    public function __construct(
        public readonly int $leadId,
        public readonly string $text,
        public readonly array $channels,
        public readonly ?int $authorId = null,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(DeliveryChannelManager $channelManager): void
    {
        $lead = Lead::find($this->leadId);
        if (! $lead) {
            Log::warning("SendLeadMessenger: Lead #{$this->leadId} not found");

            return;
        }

        foreach ($this->channels as $channelName) {
            if (! $channelManager->has($channelName)) {
                continue;
            }

            $userId = match ($channelName) {
                'telegram' => $lead->telegram_chat_id ? (string) $lead->telegram_chat_id : null,
                'vk' => $lead->vk_user_id ? (string) $lead->vk_user_id : null,
                'max' => $lead->max_user_id,
                default => null,
            };

            if (! $userId) {
                continue;
            }

            $channelManager->get($channelName)->sendMessage($userId, $this->text);

            // Автолог в историю общения — что и куда отправили.
            LeadNote::create([
                'lead_id' => $lead->id,
                'user_id' => $this->authorId,
                'type' => LeadNote::TYPE_DM,
                'channel' => $channelName,
                'body' => $this->text,
            ]);

            Log::info("SendLeadMessenger: sent to Lead #{$lead->id} via {$channelName}");
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error("SendLeadMessenger: FAILED for Lead #{$this->leadId}", [
            'error' => $e->getMessage(),
        ]);
    }
}
