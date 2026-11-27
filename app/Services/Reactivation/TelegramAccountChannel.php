<?php

declare(strict_types=1);

namespace App\Services\Reactivation;

use App\Models\TelegramBusinessConnection;
use App\Models\TelegramSupportChat;
use App\Models\User;
use App\Services\TelegramBusiness\TelegramBusinessSender;

/**
 * Канал «от имени аккаунта школы» для напоминаний должникам.
 *
 * Зачем отдельный резолвер: бизнес-бот шлёт не в `users.telegram_id`, а в
 * `chat_id` конкретного диалога с аккаунтом школы, и Bot API принимает такой
 * sendMessage только вместе с `business_connection_id`. Ни то, ни другое не
 * лежит на пользователе — первое живёт в `telegram_support_chats.linked_user_id`
 * (сшивка «чат ↔ студент»), второе — в `telegram_business_connections`.
 *
 * Поэтому «от имени аккаунта» достаёт ТОЛЬКО тех, кто уже писал в аккаунт школы
 * (их чат существует), и НЕ заменяет бота: у бота адресат — любой, кто запустил
 * бота. Честная цена канала видна в интерфейсе, а не обнаруживается по тишине
 * после рассылки.
 */
final class TelegramAccountChannel
{
    public function __construct(private readonly TelegramBusinessSender $sender) {}

    /** Есть ли вообще живой бизнес-канал: токен бота + включённое соединение. */
    public function isAvailable(): bool
    {
        return $this->sender->isConfigured() && $this->connection() !== null;
    }

    /**
     * Куда писать этому студенту от имени аккаунта.
     *
     * @return array{chat_id: int, connection_id: string}|null null — чата с аккаунтом школы нет
     */
    public function resolve(User $user): ?array
    {
        // Без токена бизнес-бота резолв бессмысленен: чат есть, отправить нечем.
        if (! $this->sender->isConfigured()) {
            return null;
        }

        $connection = $this->connection();
        if ($connection === null) {
            return null;
        }

        $chatId = TelegramSupportChat::query()
            ->where('linked_user_id', $user->id)
            ->whereNotNull('telegram_chat_id')
            // Личные диалоги: в группах/каналах напоминание о деньгах — утечка
            // долга студента перед однокурсниками.
            ->where('type', 'private')
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->value('telegram_chat_id');

        if ($chatId === null) {
            return null;
        }

        return [
            'chat_id' => (int) $chatId,
            'connection_id' => (string) $connection->business_connection_id,
        ];
    }

    /**
     * Отправить текст в уже разрешённый диалог (резолв сделан вызывающим —
     * чтобы не гонять один и тот же запрос дважды на одного студента).
     *
     * @param  array{chat_id: int, connection_id: string}  $target
     * @return array{status: string, message_id: ?int, error: ?string, telegram_error: ?string}
     */
    public function deliver(array $target, string $text): array
    {
        return $this->sender->send($target['connection_id'], $target['chat_id'], $text);
    }

    /**
     * Отправить текст от имени аккаунта, разрешив адресата по ходу.
     *
     * @return array{status: string, message_id: ?int, error: ?string, telegram_error: ?string}
     */
    public function send(User $user, string $text): array
    {
        $target = $this->resolve($user);

        if ($target === null) {
            return ['status' => 'no_channel', 'message_id' => null, 'error' => null, 'telegram_error' => null];
        }

        return $this->deliver($target, $text);
    }

    private function connection(): ?TelegramBusinessConnection
    {
        return TelegramBusinessConnection::query()
            ->where('is_enabled', true)
            ->where('can_reply', true)
            ->orderByDesc('connected_at')
            ->orderByDesc('id')
            ->first();
    }
}
