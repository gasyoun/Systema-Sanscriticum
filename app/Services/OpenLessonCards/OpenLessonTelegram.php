<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\MarketingSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Bot API глобального @samskrte_bot (токен MarketingSetting, как у ждуна и
 * TelegramDeliveryChannel). В отличие от WaitlistBotService::tg() отдаёт
 * результат наружу: отправителю карточек нужно различать «доставлено»,
 * «бот заблокирован» (403), «Telegram отказал» и «ответ потерян».
 */
class OpenLessonTelegram
{
    public const OK = 'ok';

    public const BLOCKED = 'blocked';

    public const REFUSED = 'refused';

    public const LOST = 'lost';

    /**
     * @param  array<string, mixed>  $payload
     * @param  string|null  $photoFile  локальный файл для multipart-поля photo
     * @return array{status: string, result: array<string, mixed>, description: ?string}
     */
    public function call(string $method, array $payload, ?string $photoFile = null): array
    {
        $token = (string) (MarketingSetting::cached()?->tg_bot_token ?? '');
        if ($token === '') {
            return ['status' => self::REFUSED, 'result' => [], 'description' => 'no tg_bot_token'];
        }

        $url = "https://api.telegram.org/bot{$token}/{$method}";

        try {
            $request = Http::timeout(20);
            if ($photoFile !== null) {
                $response = $request
                    ->attach('photo', (string) file_get_contents($photoFile), 'card.jpg')
                    ->post($url, $payload);
            } else {
                $response = $request->post($url, $payload);
            }
        } catch (ConnectionException $e) {
            // Запрос мог дойти — повторять нельзя (дубль хуже потери).
            Log::warning('OpenLessonTelegram: connection failed', ['method' => $method, 'error' => $e->getMessage()]);

            return ['status' => self::LOST, 'result' => [], 'description' => $e->getMessage()];
        }

        if ($response->json('ok') === true) {
            return ['status' => self::OK, 'result' => (array) $response->json('result', []), 'description' => null];
        }

        $description = (string) ($response->json('description') ?? $response->body());
        // 403 — бот заблокирован / аккаунт удалён; «chat not found» — чат
        // недостижим насовсем. Повторять таким бессмысленно.
        $status = $response->status() === 403 || str_contains(mb_strtolower($description), 'chat not found')
            ? self::BLOCKED
            : self::REFUSED;

        Log::warning('OpenLessonTelegram: telegram api not ok', [
            'method' => $method,
            'http' => $response->status(),
            'description' => $description,
        ]);

        return ['status' => $status, 'result' => [], 'description' => $description];
    }
}
