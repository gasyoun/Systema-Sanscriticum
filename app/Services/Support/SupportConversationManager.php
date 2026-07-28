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

    /** Текущий тред гостя по session-токену без создания (H536 Phase 2). */
    public function currentForGuest(string $guestToken): ?SupportConversation
    {
        return SupportConversation::query()
            ->where('guest_token', $guestToken)
            ->orderByDesc('id')
            ->first();
    }

    /** Открытый тред гостя; закрытый — переоткрыть; нет — создать (H536 Phase 2). */
    public function openForGuest(string $guestToken, ?string $guestName = null): SupportConversation
    {
        $thread = $this->currentForGuest($guestToken);

        if (! $thread) {
            return SupportConversation::create([
                'guest_token' => $guestToken,
                'guest_name' => $guestName,
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

        // Имя, если появилось позже, дописываем; существующее не затираем.
        if ($guestName && ! $thread->guest_name) {
            $thread->forceFill(['guest_name' => $guestName])->save();
        }

        return $thread;
    }

    /**
     * Тред техвопроса из Telegram-чата, автор которого НЕ привязан к аккаунту.
     *
     * Раньше такие сообщения молча отбрасывались: роутер требовал users-запись,
     * хотя ни приём, ни ответ её не используют — юзербот читает и отвечает по
     * telegram_chat_id. В итоге вопрос из учебного чата не доходил до куратора,
     * если студент не нажимал «Подключить Telegram». Тред без user_id —
     * ровно тот же приём, что у гостей веб-виджета (см. openForGuest).
     *
     * Ключ — пара «чат + автор»: в общем чате пишут разные люди, и мешать их
     * вопросы в один тред нельзя. Автор берётся из telegram_user_id сообщения.
     */
    public function openForTelegramChat(
        int $chatId,
        ?int $telegramUserId,
        ?string $authorName = null,
    ): SupportConversation {
        $thread = SupportConversation::query()
            ->whereNull('user_id')
            ->where('source_telegram_chat_id', $chatId)
            ->when(
                $telegramUserId !== null,
                fn ($query) => $query->where('source_telegram_user_id', $telegramUserId),
                fn ($query) => $query->whereNull('source_telegram_user_id'),
            )
            ->where('status', '!=', SupportConversation::STATUS_CLOSED)
            ->orderByDesc('id')
            ->first();

        if (! $thread) {
            return SupportConversation::create([
                'source_telegram_chat_id' => $chatId,
                'source_telegram_user_id' => $telegramUserId,
                'guest_name' => $authorName,
                'status' => SupportConversation::STATUS_OPEN,
                'last_message_at' => now(),
            ]);
        }

        // Имя, если появилось позже, дописываем; существующее не затираем.
        if ($authorName && ! $thread->guest_name) {
            $thread->forceFill(['guest_name' => $authorName])->save();
        }

        return $thread;
    }

    /** Открыть/переоткрыть гостевой тред и привязать сообщение (H536 Phase 2). */
    public function recordGuestMessage(
        string $guestToken,
        ChatMessage $message,
        ?string $guestName = null,
        ?\DateTimeInterface $at = null,
    ): SupportConversation {
        $thread = $this->openForGuest($guestToken, $guestName);
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
