<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use App\Support\UnifiedMessage;
use Illuminate\Support\Collection;

/**
 * Read-слой над двумя хранилищами поддержки (веб-чат + импорт TG). Отдаёт единый
 * хронологический поток UnifiedMessage по конкретному пользователю. Только чтение:
 * запись остаётся в StudentChatService/Helpdesk (веб) и sync-пайплайне (TG).
 * См. docs/support-subsystem-map.md.
 */
class UnifiedInboxReader
{
    /**
     * Все сообщения поддержки пользователя из обоих каналов, отсортированные по
     * времени отправки (по возрастанию).
     *
     * @return Collection<int, UnifiedMessage>
     */
    public function forUser(User|int $user): Collection
    {
        $userId = $user instanceof User ? $user->id : $user;

        $web = ChatMessage::query()
            ->with('answeredBy:id,name')
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ChatMessage $message) => UnifiedMessage::fromChatMessage($message));

        $telegram = TelegramSupportMessage::query()
            ->with(['chat', 'contact', 'responder:id,name'])
            ->where(function ($query) use ($userId) {
                $query->whereHas('chat', fn ($q) => $q->where('linked_user_id', $userId))
                    ->orWhereHas('contact', fn ($q) => $q->where('linked_user_id', $userId));
            })
            ->orderBy('sent_at')
            ->get()
            ->map(fn (TelegramSupportMessage $message) => UnifiedMessage::fromTelegramSupportMessage($message));

        return $web->concat($telegram)
            ->sortBy(fn (UnifiedMessage $message) => $message->sentAt->getTimestamp())
            ->values();
    }

    /**
     * Сообщения одного треда — для диалогов БЕЗ привязки к пользователю
     * (веб-гость и техвопрос из Telegram от автора без users-записи), где
     * forUser() неприменим по определению.
     *
     * Тред живёт ровно в одном хранилище: есть source_telegram_chat_id — вся
     * переписка в telegram_support_messages, иначе в chat_messages. Поэтому
     * слияния здесь нет, в отличие от forUser().
     *
     * @return Collection<int, UnifiedMessage>
     */
    public function forConversation(SupportConversation $thread): Collection
    {
        if ($thread->source_telegram_chat_id !== null) {
            return $thread->telegramMessages()
                ->with(['chat', 'contact', 'responder:id,name'])
                // Импорт мог идти пачками вразнобой, поэтому id ≠ хронология.
                ->orderBy('sent_at')
                ->orderBy('id')
                ->get()
                ->map(fn (TelegramSupportMessage $message) => UnifiedMessage::fromTelegramSupportMessage($message))
                ->values();
        }

        return $thread->chatMessages()
            ->with('answeredBy:id,name')
            ->orderBy('id')
            ->get()
            ->map(fn (ChatMessage $message) => UnifiedMessage::fromChatMessage($message))
            ->values();
    }
}
