<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
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
            ->where('user_id', $userId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ChatMessage $message) => UnifiedMessage::fromChatMessage($message));

        $telegram = TelegramSupportMessage::query()
            ->with(['chat', 'contact'])
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
}
