<?php

declare(strict_types=1);

namespace App\Services\TelegramBusiness;

use App\Models\TelegramBusinessConnection;
use Carbon\CarbonImmutable;

/**
 * H5065 — `business_message` из Bot API в тот же нормализованный payload, что
 * даёт MadelineProto-синк (TelegramSupportSyncService::normalizeMadelineMessage).
 *
 * Зачем приводить к одному виду, а не писать свой ingest: у синка уже есть
 * чаты, контакты, дедуп по (аккаунт, чат, message_id), авто-линк по телефону,
 * дневные роллапы, роутинг техвопросов и прогон автоответа. Второй ingest
 * означал бы второй набор тех же правил и первое же расхождение между ними
 * (классический случай — линковка студента, которая в одной полосе работает,
 * а в другой нет).
 *
 * ГЛАВНОЕ в этом классе — направление сообщения. Telegram присылает
 * `business_message` и на сообщения СТУДЕНТА, и на сообщения самого владельца
 * аккаунта (человек отвечает из своего клиента). Если считать их одним
 * потоком, бот начнёт отвечать на собственные сообщения школы, а автоответ
 * уйдёт студенту второй раз. Поэтому владелец определяется по подключению
 * (`owner_telegram_user_id`), и его сообщения помечаются `outgoing`.
 */
final class TelegramBusinessNormalizer
{
    /**
     * @param  array<string, mixed>  $message
     * @return array<string, mixed>|null null — сообщение непригодно (нет чата/текста)
     */
    public function normalize(array $message, TelegramBusinessConnection $connection): ?array
    {
        $chat = $message['chat'] ?? null;
        if (! is_array($chat) || ! isset($chat['id'], $message['message_id'], $message['date'])) {
            return null;
        }

        $text = trim((string) ($message['text'] ?? $message['caption'] ?? ''));
        if ($text === '') {
            // Стикер, фото без подписи, системное «сообщение удалено»: отвечать
            // не на что, а пустая строка в ленте поддержки выглядит как сбой.
            return null;
        }

        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $fromId = isset($from['id']) ? (int) $from['id'] : 0;
        $isOwner = $fromId !== 0 && $fromId === (int) $connection->owner_telegram_user_id;
        $chatType = (string) ($chat['type'] ?? 'private');

        return [
            'telegram_chat_id' => (int) $chat['id'],
            'telegram_message_id' => (int) $message['message_id'],
            // У исходящего владельца автора-контакта нет: contact привязывается
            // к студенту, а не к аккаунту школы (upsertContact на пустом
            // telegram_user_id и outgoing возвращает null).
            'telegram_user_id' => $isOwner ? null : ($fromId ?: null),
            'direction' => $isOwner ? 'outgoing' : 'incoming',
            'text' => $text,
            'sent_at' => CarbonImmutable::createFromTimestamp((int) $message['date'], config('app.timezone'))->toDateTimeString(),
            'contact_name' => $isOwner ? null : $this->displayName($from),
            'contact_username' => $isOwner ? null : ($from['username'] ?? null),
            'chat_type' => $chatType,
            'chat_title' => $chat['title'] ?? null,
            'chat_username' => $chat['username'] ?? null,
            'reply_to_msg_id' => isset($message['reply_to_message']['message_id'])
                ? (int) $message['reply_to_message']['message_id']
                : null,
            // Ключ, ради которого вся полоса существует: по нему отправка
            // уходит ОТ ИМЕНИ аккаунта (см. BusinessSupportReplyDrainer).
            'business_connection_id' => (string) ($message['business_connection_id'] ?? $connection->business_connection_id),
        ];
    }

    /**
     * @param  array<string, mixed>  $from
     */
    private function displayName(array $from): ?string
    {
        $name = trim(($from['first_name'] ?? '').' '.($from['last_name'] ?? ''));

        return $name === '' ? null : $name;
    }
}
