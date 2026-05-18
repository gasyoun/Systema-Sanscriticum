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
        $payload = $msg['payload'] ?? null;

        if (! $userId) {
            return;
        }

        // VK передаёт ?ref=TOKEN из deep-link'а как JSON-payload первого сообщения.
        $token = null;
        if ($payload) {
            $decoded = json_decode($payload, true);
            $token = $decoded['token'] ?? $decoded['ref'] ?? null;
        }

        // Fallback — юзер мог ввести токен текстом.
        if (! $token) {
            $text = trim($msg['text'] ?? '');
            if (preg_match('/^[a-zA-Z0-9]{12,16}$/', $text)) {
                $token = $text;
            }
        }

        if (! $token) {
            Log::info('VK callback: no token in message', ['user_id' => $userId]);

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
