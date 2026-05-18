<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Доставляет lead-magnet файл юзеру.
 * Вызывается из webhook-jobs после того как юзер запустил бота
 * и его user_id уже записан в Lead.
 */
final class SendLeadMagnet implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public readonly int $leadId,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(DeliveryChannelManager $channels): void
    {
        $lead = Lead::with('landingPage')->find($this->leadId);

        if (! $lead) {
            Log::warning("SendLeadMagnet: Lead #{$this->leadId} not found");

            return;
        }

        // Идемпотентность — повторно не отправляем.
        if ($lead->magnet_delivered_at !== null) {
            Log::info("SendLeadMagnet: Lead #{$this->leadId} already delivered, skip");

            return;
        }

        $landing = $lead->landingPage;

        if (! $landing || ! $landing->hasLeadMagnet()) {
            Log::warning("SendLeadMagnet: Lead #{$this->leadId} — landing has no magnet");

            return;
        }

        $channelName = $lead->magnet_channel;
        if (! $channelName || ! $channels->has($channelName)) {
            Log::warning("SendLeadMagnet: unknown channel '{$channelName}' for Lead #{$this->leadId}");

            return;
        }

        $userId = match ($channelName) {
            'telegram' => $lead->telegram_chat_id ? (string) $lead->telegram_chat_id : null,
            'vk' => $lead->vk_user_id ? (string) $lead->vk_user_id : null,
            'max' => $lead->max_user_id,
            default => null,
        };

        if (! $userId) {
            Log::error("SendLeadMagnet: no user_id in '{$channelName}' for Lead #{$this->leadId}");

            return;
        }

        $filePath = Storage::disk('public')->path($landing->lead_magnet_file_path);

        if (! file_exists($filePath)) {
            Log::error("SendLeadMagnet: file not found at {$filePath}");

            return;
        }

        $caption = $landing->lead_magnet_caption
            ?: "Ваш бонус — «{$landing->lead_magnet_title}» 🎁";

        $channels->get($channelName)->sendDocument($userId, $filePath, $caption);

        $lead->update(['magnet_delivered_at' => now()]);

        Log::info("SendLeadMagnet: delivered to Lead #{$this->leadId} via {$channelName}");
    }

    public function failed(Throwable $e): void
    {
        Log::error("SendLeadMagnet: FAILED for Lead #{$this->leadId}", [
            'error' => $e->getMessage(),
        ]);
    }
}
