<?php

declare(strict_types=1);

namespace App\Services\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Резервный SMS-канал (SMS.ru) для students:send-login-invites — срабатывает,
 * когда у студента нет ни Telegram, ни VK, а email уже отправлялся/ненадёжен.
 * Пусто services.sms_ru.api_id → канал выключен (isConfigured() === false),
 * вызывающий код обязан сам это проверить перед send().
 */
final class SmsRuChannel
{
    public function isConfigured(): bool
    {
        return filled(config('services.sms_ru.api_id'));
    }

    /**
     * @param  string  $phone  в любом формате с цифрами (7/8-код РФ) — SMS.ru сам
     *                         нормализует; здесь только убираем нецифровые символы.
     */
    public function send(string $phone, string $text): bool
    {
        $apiId = (string) config('services.sms_ru.api_id');
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if ($apiId === '' || $digits === '') {
            return false;
        }

        $response = Http::asForm()->post('https://sms.ru/sms/send', [
            'api_id' => $apiId,
            'to' => $digits,
            'msg' => $text,
            'json' => 1,
        ]);

        $body = $response->json();
        $status = $body['sms'][$digits]['status'] ?? null;

        if (! $response->successful() || ($body['status'] ?? null) !== 'OK' || $status !== 'OK') {
            Log::error('SMS.ru send failed', ['phone' => $digits, 'response' => $body]);

            return false;
        }

        return true;
    }
}
