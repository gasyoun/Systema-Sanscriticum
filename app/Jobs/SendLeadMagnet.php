<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Messaging\DeliveryChannelManager;
use App\Services\Messaging\TelegramDeliveryChannel;
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
        $lead = Lead::with('landingPage.bot')->find($this->leadId);

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

        // Curator переименовывает файлы в ULID — юзеру в чате это выглядит уродливо.
        // Строим «человеческое» имя из lead_magnet_title (если есть) + расширения исходника.
        $displayName = $this->buildDisplayName($landing->lead_magnet_title, $filePath);

        // Для telegram, если у лендинга свой бот — отдаём файл его токеном
        // (юзер разговаривает именно с ним), иначе глобальным из MarketingSetting.
        $channel = $channels->get($channelName);
        if ($channelName === 'telegram' && $channel instanceof TelegramDeliveryChannel) {
            $bot = $landing->bot;
            if ($bot && $bot->isUsable()) {
                $channel = $channel->usingCredentials($bot->tg_bot_token, $bot->tg_bot_username);
            }
        }

        $channel->sendDocument($userId, $filePath, $caption, $displayName);

        $lead->update(['magnet_delivered_at' => now()]);

        Log::info("SendLeadMagnet: delivered to Lead #{$this->leadId} via {$channelName}");
    }

    public function failed(Throwable $e): void
    {
        Log::error("SendLeadMagnet: FAILED for Lead #{$this->leadId}", [
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Кириллица в Telegram filename отображается нормально; для VK безопаснее
     * без слешей/двоеточий — оставляем буквы/цифры/пробелы/дефисы/подчёркивания.
     * Если title пустой — fallback на basename ULID-файла (старое поведение).
     */
    private function buildDisplayName(?string $title, string $filePath): ?string
    {
        if (! $title) {
            return null;
        }

        $cleaned = preg_replace('~[\\\\/:*?"<>|]+~u', '', $title);
        $cleaned = trim(preg_replace('~\s+~u', ' ', $cleaned));

        if ($cleaned === '') {
            return null;
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        return $ext ? "{$cleaned}.{$ext}" : $cleaned;
    }
}
