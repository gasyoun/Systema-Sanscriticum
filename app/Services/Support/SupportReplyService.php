<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Jobs\DeliverSupportReply;
use App\Models\ChatMessage;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Маршрутизация ответа куратора в тот канал, где живёт разговор (см.
 * docs/support-subsystem-map.md). Активируется фича-флагом support_unified_reply.
 *
 * Веб/бот-каналы отвечает как прежде сам Helpdesk (ChatMessage + bot API). Этот
 * сервис добавляет ветку для импортированного TG-support (userbot): пишет
 * ИСХОДЯЩЕЕ TelegramSupportMessage, привязывает к треду и ставит в очередь
 * доставку через userbot ([[DeliverSupportReply]]). Пока userbot не настроен —
 * запись остаётся pending, job тихо выходит.
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

        $message = $this->createPendingOutgoing($accountId, $chat, $text, $curator);

        $this->conversations->recordMessage($user, $message, $message->sent_at);

        // Реальную доставку через userbot ставим в очередь только когда он включён;
        // иначе запись остаётся pending до настройки userbot (без пустого job'а).
        $queued = (bool) config('services.telegram_support.enabled');
        if ($queued) {
            DeliverSupportReply::dispatch($message->id);
        }

        Log::info('SupportReplyService: ответ записан в TG-support', [
            'user_id' => $user->id,
            'chat_id' => $chat->telegram_chat_id,
            'message_id' => $message->id,
            'delivery_queued' => $queued,
        ]);

        return $message;
    }

    /**
     * Создать pending-исходящее с гарантированно уникальным placeholder-id.
     * Реального telegram_message_id ещё нет; берём (min по чату) − 1 — строго
     * убывающий отрицательный id, не сталкивающийся с реальными (положительными).
     * На гонку (уникальный индекс account+chat+message_id) — ограниченный ретрай.
     */
    private function createPendingOutgoing(
        int $accountId,
        TelegramSupportChat $chat,
        string $text,
        ?User $curator,
    ): TelegramSupportMessage {
        for ($attempt = 0; ; $attempt++) {
            try {
                return TelegramSupportMessage::create([
                    'telegram_support_account_id' => $accountId,
                    'telegram_support_chat_id' => $chat->id,
                    'telegram_chat_id' => $chat->telegram_chat_id,
                    'telegram_message_id' => $this->nextPendingMessageId($accountId, (int) $chat->telegram_chat_id),
                    'direction' => 'outgoing',
                    'role' => 'human',
                    'responder_type' => 'human',
                    'responder_user_id' => $curator?->id,
                    'text' => $text,
                    'raw_payload' => ['pending_delivery' => true, 'via' => 'helpdesk_unified_reply'],
                    'sent_at' => now(),
                ]);
            } catch (QueryException $e) {
                // 23000 — нарушение уникального индекса (гонка двух ответов). Пересчитываем id.
                if ($attempt < 3 && $e->getCode() === '23000') {
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * Следующий placeholder-id: строго отрицательный и убывающий. Берём минимум
     * из (существующий минимум по account+chat, 0) и вычитаем 1 — так placeholder
     * всегда ≤ −1 (не столкнётся с реальными положительными id), а каждый новый
     * ниже предыдущего.
     */
    private function nextPendingMessageId(int $accountId, int $chatId): int
    {
        $min = TelegramSupportMessage::query()
            ->where('telegram_support_account_id', $accountId)
            ->where('telegram_chat_id', $chatId)
            ->min('telegram_message_id');

        return min((int) ($min ?? 0), 0) - 1;
    }
}
