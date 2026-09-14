<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Lead;
use App\Services\Leads\LeadMagnetDispatcher;
use App\Services\Leads\WaitlistWelcome;
use App\Services\Messaging\DeliveryChannelManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

final class ProcessMaxMagnetUpdate implements ShouldQueue
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
        if (($this->update['update_type'] ?? '') !== 'message_created') {
            return;
        }

        $message = $this->update['message'] ?? [];
        $userId = (string) ($message['sender']['user_id'] ?? '');
        $text = trim($message['body']['text'] ?? '');

        if (! $userId) {
            return;
        }

        // Max передаёт payload из deep-link'а в start_payload (формат может варьироваться)
        // или юзер шлёт токен текстом первым сообщением. Токены строго 12 символов
        // (см. LeadController::attachMagnet → Str::random(12)).
        $token = $this->update['message']['start_payload'] ?? null;
        if (! $token && preg_match('/^[a-zA-Z0-9]{12}$/', $text)) {
            $token = $text;
        }
        // /start TOKEN формат как в TG
        if (! $token && preg_match('~^/start\s+([a-zA-Z0-9]{12})$~', $text, $m)) {
            $token = $m[1];
        }

        if (! $token) {
            Log::info('Max update: no token', ['user_id' => $userId]);

            return;
        }

        $lead = Lead::where('magnet_token', $token)->first();

        if (! $lead) {
            Log::warning('Max: unknown magnet_token', ['token' => $token]);

            return;
        }

        if (! $lead->max_user_id) {
            $lead->update(['max_user_id' => $userId]);

            // H3339: подписчик статусов получает словарь при первой привязке.
            WaitlistWelcome::sendIfFreshlyBound($lead->fresh() ?? $lead, 'max', $userId, app(DeliveryChannelManager::class));
        }

        LeadMagnetDispatcher::deliverOrDefer($lead, 'max');
    }

    /**
     * Исчерпаны ретраи: логируем, чтобы «магнит не пришёл» не терялся молча.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ProcessMaxMagnetUpdate: доставка магнита не удалась', [
            'user_id' => $this->update['message']['sender']['user_id'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
