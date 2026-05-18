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

final class ProcessVkMagnetCallback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public readonly array $event,
    ) {
        $this->onQueue('webhooks');
    }

    public function handle(): void
    {
        if (($this->event['type'] ?? '') !== 'message_new') {
            return;
        }

        $msg = $this->event['object']['message'] ?? [];
        $userId = $msg['from_id'] ?? null;

        if (! $userId) {
            return;
        }

        // VK передаёт ?ref=TOKEN из deep-link'а как ОТДЕЛЬНОЕ поле message.ref
        // (а не внутри JSON-payload, как ошибочно предполагал старый код).
        // Документация: https://dev.vk.com/api/community-events/json-schema → message.ref/ref_source
        // Поле появляется только в самом первом сообщении после перехода по реф-ссылке.
        $token = $msg['ref'] ?? null;

        // На всякий случай — старая логика payload (если бот когда-нибудь начнёт
        // отправлять InlineKeyboard с payload {"token": "..."}).
        if (! $token && ! empty($msg['payload'])) {
            $decoded = json_decode($msg['payload'], true);
            $token = $decoded['token'] ?? $decoded['ref'] ?? null;
        }

        // Fallback — юзер мог ввести токен текстом. Токены строго 12 символов
        // (см. LeadController::attachMagnet → Str::random(12)).
        if (! $token) {
            $text = trim($msg['text'] ?? '');
            if (preg_match('/^[a-zA-Z0-9]{12}$/', $text)) {
                $token = $text;
            }
        }

        if (! $token) {
            Log::info('VK callback: no token in message', [
                'user_id' => $userId,
                'msg_keys' => array_keys($msg),
                'text' => $msg['text'] ?? null,
                'has_ref' => isset($msg['ref']),
                'has_payload' => isset($msg['payload']),
            ]);

            return;
        }

        $lead = Lead::where('magnet_token', $token)->first();

        if (! $lead) {
            Log::warning('VK: unknown magnet_token', ['token' => $token]);

            return;
        }

        if (! $lead->vk_user_id) {
            $lead->update(['vk_user_id' => $userId]);
        }

        SendLeadMagnet::dispatch($lead->id);
    }
}
