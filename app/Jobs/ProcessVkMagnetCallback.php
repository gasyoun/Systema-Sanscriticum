<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\LandingBot;
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
        $type = $this->event['type'] ?? '';

        // Поддерживаем входящее сообщение и нажатие кнопки (для VK-анкеты в n8n).
        [$userId, $token] = match ($type) {
            'message_new' => $this->fromMessageNew(),
            'message_event' => [$this->event['object']['user_id'] ?? null, null],
            default => [null, null],
        };

        if (! $userId) {
            return;
        }

        // 1) Выдача магнита по ref-токену (только первое сообщение после deep-link).
        $refLead = null;
        if ($token) {
            $refLead = Lead::where('magnet_token', $token)->first();
            if ($refLead) {
                if (! $refLead->vk_user_id) {
                    $refLead->update(['vk_user_id' => $userId]);

                    // H3339: подписчик статусов получает словарь при первой привязке.
                    WaitlistWelcome::sendIfFreshlyBound($refLead->fresh() ?? $refLead, 'vk', (string) $userId, app(DeliveryChannelManager::class));
                }
                LeadMagnetDispatcher::deliverOrDefer($refLead, 'vk');
            } else {
                Log::warning('VK: unknown magnet_token', ['token' => $token]);
            }
        }

        // 2) Форвард в n8n по лендингу лида (анкета/прогрев).
        // Лендинг: с ref — из ref-лида; иначе — последний лид по vk_user_id.
        // Безрефных без привязки к лендингу — игнорируем (не форвардим).
        $lead = $refLead ?: Lead::where('vk_user_id', $userId)->latest('id')->first();

        if (! $lead || ! $lead->landing_page_id) {
            Log::info('VK callback: лид/лендинг не найден — форвард пропущен', ['vk_user_id' => $userId]);

            return;
        }

        $bot = LandingBot::where('landing_page_id', $lead->landing_page_id)->first();

        if ($bot && $bot->isVkForwardEnabled()) {
            ForwardUpdateToN8n::dispatch($bot->vk_n8n_forward_url, $this->event);
        }
    }

    /**
     * Достаёт user_id и magnet-токен из события message_new.
     *
     * @return array{0: int|string|null, 1: string|null}
     */
    private function fromMessageNew(): array
    {
        $msg = $this->event['object']['message'] ?? [];
        $userId = $msg['from_id'] ?? null;

        // VK передаёт ?ref=TOKEN из deep-link'а как отдельное поле message.ref
        // (появляется только в самом первом сообщении после перехода по реф-ссылке).
        $token = $msg['ref'] ?? null;

        // Подстраховка: payload InlineKeyboard {"token": "..."} / {"ref": "..."}.
        if (! $token && ! empty($msg['payload'])) {
            $decoded = json_decode((string) $msg['payload'], true);
            $token = $decoded['token'] ?? $decoded['ref'] ?? null;
        }

        // Fallback — юзер мог ввести токен текстом (строго 12 символов, см. attachMagnet).
        if (! $token) {
            $text = trim((string) ($msg['text'] ?? ''));
            if (preg_match('/^[a-zA-Z0-9]{12}$/', $text)) {
                $token = $text;
            }
        }

        return [$userId, $token];
    }

    /**
     * Исчерпаны ретраи: логируем, чтобы «магнит не пришёл» не терялся молча.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('ProcessVkMagnetCallback: доставка магнита не удалась', [
            'type' => $this->event['type'] ?? null,
            'error' => $e->getMessage(),
        ]);
    }
}
