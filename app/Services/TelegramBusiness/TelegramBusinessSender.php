<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use App\Support\TelegramSendGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * H5065 — отправка сообщения ОТ ИМЕНИ аккаунта через Telegram Business.
 *
 * Механика: Bot API принимает `sendMessage` с параметром `business_connection_id`
 * и в этом случае сообщение уходит не от бота, а от аккаунта владельца — ровно
 * так студент и видит «ORS от управляет этим чатом» в шапке диалога.
 *
 * Контракт дедупа — тот же, что у любого нового отправляющего узла в этом
 * репозитории (CLAUDE.md, инцидент 24-08-2026): клейм TelegramSendGuard ДО
 * вызова API. Разбор трёх исходов:
 *  - Telegram ответил 4xx/5xx → доставки точно не было: release() и ошибка
 *    наверх (повтор безопасен);
 *  - транспортный сбой без ответа → клейм НЕ отпускаем: «запрос не ушёл» и
 *    «ответ потерян после отправки» неразличимы, а подавленный повтор дешевле
 *    дубля в личке студента;
 *  - успех → клейм живёт окно TTL, повтор подавлен.
 *
 * parse_mode НЕ ставится намеренно. Тексты ответов в этой полосе — те же, что
 * у MadelineProto-лички (faqDraft и LLM-черновик), и там разметка не
 * включается: поставить HTML здесь означало бы, что один и тот же ответ
 * выглядит по-разному в двух полосах, а «голые» < > & в тексте внезапно
 * становятся ошибкой разбора Telegram.
 */
final class TelegramBusinessSender
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.telegram_business.token', '')) !== '';
    }

    /**
     * @return array{status: string, message_id: ?int, error: ?string} status: ok|suppressed|error
     */
    public function send(
        string $businessConnectionId,
        int $chatId,
        string $text,
        ?int $replyToMessageId = null,
    ): array {
        if (! $this->isConfigured()) {
            return ['status' => 'error', 'message_id' => null, 'error' => 'TELEGRAM_BUSINESS_BOT_TOKEN не задан'];
        }

        if (trim($text) === '') {
            return ['status' => 'error', 'message_id' => null, 'error' => 'пустой текст'];
        }

        if (! TelegramSendGuard::claim((string) $chatId, $text)) {
            Log::info('TelegramBusinessSender: duplicate suppressed by send guard', ['chat_id' => $chatId]);

            return ['status' => 'suppressed', 'message_id' => null, 'error' => null];
        }

        $payload = [
            'business_connection_id' => $businessConnectionId,
            'chat_id' => $chatId,
            'text' => $text,
        ];

        if ($replyToMessageId !== null && $replyToMessageId > 0) {
            $payload['reply_to_message_id'] = $replyToMessageId;
        }

        $timeout = max(3, (int) config('services.telegram_business.timeout_seconds', 15));

        try {
            $response = Http::timeout($timeout)->post($this->endpoint(), $payload);
        } catch (ConnectionException $e) {
            // Ответ потерян: клейм держим, повтора не будет (см. контракт выше).
            Log::warning('TelegramBusinessSender: transport failure, claim kept', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'message_id' => null, 'error' => 'transport: '.$e->getMessage()];
        }

        if ($response->failed()) {
            TelegramSendGuard::release((string) $chatId, $text);

            return [
                'status' => 'error',
                'message_id' => null,
                'error' => 'http '.$response->status().': '.mb_substr((string) $response->body(), 0, 300),
            ];
        }

        $messageId = $response->json('result.message_id');

        return [
            'status' => 'ok',
            'message_id' => is_numeric($messageId) ? (int) $messageId : null,
            'error' => null,
        ];
    }

    private function endpoint(): string
    {
        return 'https://api.telegram.org/bot'.trim((string) config('services.telegram_business.token', '')).'/sendMessage';
    }
}
