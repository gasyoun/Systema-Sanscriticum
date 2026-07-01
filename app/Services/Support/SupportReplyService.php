<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Маршрутизация ответа куратора в тот канал, где живёт разговор (см.
 * docs/support-subsystem-map.md). Активируется фича-флагом support_unified_reply.
 *
 * Веб/бот-каналы отвечает как прежде сам Helpdesk (ChatMessage + bot API). Этот
 * сервис добавляет ветку для импортированного TG-support (userbot): пишет
 * ИСХОДЯЩЕЕ TelegramSupportMessage и привязывает к треду. Реальная доставка через
 * userbot ещё НЕ подключена — сообщение помечается pending и логируется.
 */
class SupportReplyService
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_TELEGRAM_SUPPORT = 'telegram_support';

    public function __construct(private readonly SupportConversationManager $conversations) {}

    /** Канал, где сейчас живёт разговор — по последнему сообщению любого стора. */
    public function activeChannel(User|int $user): string
    {
        $userId = $user instanceof User ? $user->id : $user;

        $lastWebAt = ChatMessage::query()
            ->where('user_id', $userId)
            ->max('created_at');

        $lastTgAt = TelegramSupportMessage::query()
            ->where(function ($query) use ($userId) {
                $query->whereHas('chat', fn ($q) => $q->where('linked_user_id', $userId))
                    ->orWhereHas('contact', fn ($q) => $q->where('linked_user_id', $userId));
            })
            ->max('sent_at');

        if ($lastTgAt && (! $lastWebAt || $lastTgAt > $lastWebAt)) {
            return self::CHANNEL_TELEGRAM_SUPPORT;
        }

        return self::CHANNEL_WEB;
    }

    /**
     * Записать ответ куратора в импортированный TG-support как исходящее и
     * привязать к треду. Доставка через userbot пока не подключена (pending).
     */
    public function replyViaSupportChannel(User $user, string $text, ?User $curator): ?TelegramSupportMessage
    {
        $chat = TelegramSupportChat::query()
            ->where('linked_user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->first();

        if (! $chat) {
            return null;
        }

        $accountId = $chat->messages()->max('telegram_support_account_id')
            ?? TelegramSupportAccount::query()->min('id');

        if (! $accountId) {
            return null;
        }

        $message = TelegramSupportMessage::create([
            'telegram_support_account_id' => $accountId,
            'telegram_support_chat_id' => $chat->id,
            'telegram_chat_id' => $chat->telegram_chat_id,
            // Синтетический отрицательный id — реального message_id ещё нет (не отправлено).
            'telegram_message_id' => -1 * (int) round(microtime(true) * 1000),
            'direction' => 'outgoing',
            'role' => 'human',
            'responder_type' => 'human',
            'responder_user_id' => $curator?->id,
            'text' => $text,
            'raw_payload' => ['pending_delivery' => true, 'via' => 'helpdesk_unified_reply'],
            'sent_at' => now(),
        ]);

        $this->conversations->recordMessage($user, $message, $message->sent_at);

        Log::info('SupportReplyService: ответ записан в TG-support как pending (userbot-доставка не подключена)', [
            'user_id' => $user->id,
            'chat_id' => $chat->telegram_chat_id,
            'message_id' => $message->id,
        ]);

        return $message;
    }
}
