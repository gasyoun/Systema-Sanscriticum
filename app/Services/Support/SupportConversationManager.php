<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Жизненный цикл операционного треда поддержки (см. docs/support-subsystem-map.md).
 * Модель — один активный тред на пользователя, reopenable: новое входящее в
 * закрытый тред открывает его заново, история сохраняется. Тред группирует
 * сообщения обоих каналов через nullable FK — таблицы не сливаются.
 */
class SupportConversationManager
{
    /** Текущий тред пользователя без создания (для чтения UI). */
    public function currentFor(User|int $user): ?SupportConversation
    {
        return SupportConversation::query()
            ->where('user_id', $this->userId($user))
            ->orderByDesc('id')
            ->first();
    }

    /** Получить открытый тред пользователя; закрытый — переоткрыть; нет — создать. */
    public function openFor(User|int $user): SupportConversation
    {
        $userId = $this->userId($user);
        $thread = $this->currentFor($userId);

        if (! $thread) {
            return SupportConversation::create([
                'user_id' => $userId,
                'status' => SupportConversation::STATUS_OPEN,
                'last_message_at' => now(),
            ]);
        }

        if ($thread->status === SupportConversation::STATUS_CLOSED) {
            $thread->forceFill([
                'status' => SupportConversation::STATUS_OPEN,
                'closed_at' => null,
            ])->save();
        }

        return $thread;
    }

    /** Привязать сообщение к треду и подтянуть last_message_at. */
    public function attach(
        SupportConversation $thread,
        ChatMessage|TelegramSupportMessage $message,
        ?\DateTimeInterface $at = null,
    ): void {
        if ($message->support_conversation_id !== $thread->id) {
            $message->forceFill(['support_conversation_id' => $thread->id])->save();
        }

        $moment = $at ? Carbon::instance(Carbon::parse($at)) : now();

        if (! $thread->last_message_at || $thread->last_message_at->lt($moment)) {
            $thread->forceFill(['last_message_at' => $moment])->save();
        }
    }

    /** Удобный хелпер для write-сайтов: открыть/переоткрыть тред и привязать сообщение. */
    public function recordMessage(
        User|int $user,
        ChatMessage|TelegramSupportMessage $message,
        ?\DateTimeInterface $at = null,
    ): SupportConversation {
        $thread = $this->openFor($user);
        $this->attach($thread, $message, $at);

        return $thread;
    }

    public function close(SupportConversation $thread): void
    {
        $thread->forceFill([
            'status' => SupportConversation::STATUS_CLOSED,
            'closed_at' => now(),
        ])->save();
    }

    private function userId(User|int $user): int
    {
        return $user instanceof User ? $user->id : $user;
    }
}
