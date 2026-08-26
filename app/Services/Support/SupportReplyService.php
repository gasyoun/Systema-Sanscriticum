<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\ChatMessage;
use App\Models\SupportConversation;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportChat;
use App\Models\TelegramSupportMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Маршрутизация ответа куратора в тот канал, где живёт разговор (см.
 * docs/support-subsystem-map.md). Активируется фича-флагом support_unified_reply
 * или для очереди queue=technical (ответ всегда через userbot в source peer).
 *
 * Веб/бот-каналы отвечает как прежде сам Helpdesk (ChatMessage + bot API). Этот
 * сервис добавляет ветку для импортированного TG-support (userbot): пишет
 * ИСХОДЯЩЕЕ TelegramSupportMessage и привязывает его к треду. Сам он ничего не
 * отправляет: запись остаётся pending, а увозит её ближайший заход синка
 * ({@see PendingSupportReplyDrainer}).
 *
 * Отправлять отсюда или из очереди НЕЛЬЗЯ. До 15-08-2026 доставку делал джоб в
 * воркере Horizon — MadelineProto ловил там SIGTERM и разваливал событийный
 * цикл, и за всё время не ушло ни одного сообщения. Единственный владелец
 * MTProto-сессии — короткоживущий процесс telegram-support:sync.
 */
class SupportReplyService
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_TELEGRAM_SUPPORT = 'telegram_support';

    /** Исходы ручного досыла — каждый отображается в свой тост хелпдеска. */
    public const RESEND_QUEUED = 'queued';

    public const RESEND_ALREADY_DELIVERED = 'already_delivered';

    public const RESEND_NOT_TRACKED = 'not_tracked';

    public const RESEND_DISABLED = 'disabled';

    public const RESEND_THROTTLED = 'throttled';

    /** Пауза между ручными досылами одного сообщения, секунды. */
    private const RESEND_THROTTLE_SECONDS = 30;

    public function __construct(private readonly SupportConversationManager $conversations) {}

    /** Канал, где сейчас живёт разговор — по последнему сообщению любого стора. */
    public function activeChannel(User|int $user): string
    {
        $userId = $user instanceof User ? $user->id : $user;

        $thread = $this->conversations->currentFor($userId);
        if ($thread?->isTechnical() && $thread->source_telegram_chat_id) {
            return self::CHANNEL_TELEGRAM_SUPPORT;
        }

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
     * привязать к треду. Peer: source_telegram_chat_id треда → иначе last TG msg.
     */
    public function replyViaSupportChannel(User $user, string $text, ?User $curator): ?TelegramSupportMessage
    {
        $thread = $this->conversations->currentFor($user);
        $chat = $this->resolveTargetChat($user, $thread);

        if (! $chat) {
            return null;
        }

        $accountId = $chat->messages()->max('telegram_support_account_id')
            ?? TelegramSupportAccount::query()->min('id');

        if (! $accountId) {
            return null;
        }

        $replyToMsgId = $thread?->source_telegram_message_id
            ? (int) $thread->source_telegram_message_id
            : null;

        $message = $this->createPendingOutgoing(
            (int) $accountId,
            $chat,
            $text,
            $curator,
            $replyToMsgId,
        );

        $this->conversations->recordMessage($user, $message, $message->sent_at);

        Log::info('SupportReplyService: ответ записан в TG-support', [
            'user_id' => $user->id,
            'chat_id' => $chat->telegram_chat_id,
            'message_id' => $message->id,
            'reply_to_msg_id' => $replyToMsgId,
        ]);

        return $message;
    }

    /**
     * Pending исходящее от бота в личку саппорта (H3233). Куратор = null,
     * responder_type=ai. Увозит ближайший заход синка, как человеческий ответ.
     */
    public function queueAiReply(User $user, string $text, ?int $replyToMsgId = null): ?TelegramSupportMessage
    {
        $thread = $this->conversations->currentFor($user);
        $chat = $this->resolveTargetChat($user, $thread);

        if (! $chat) {
            return null;
        }

        $accountId = $chat->messages()->max('telegram_support_account_id')
            ?? TelegramSupportAccount::query()->min('id');

        if (! $accountId) {
            return null;
        }

        $message = $this->createPendingOutgoing(
            (int) $accountId,
            $chat,
            $text,
            null,
            $replyToMsgId,
            SupportDmAutoReply::VIA,
            'ai',
        );

        $this->conversations->recordMessage($user, $message, $message->sent_at);

        return $message;
    }

    /**
     * Pending исходящее в личку БЕЗ привязанного юзера (H3542): приглашение
     * связать Telegram с кабинетом. От queueAiReply отличается тем, что юзера
     * нет — некуда ставить users-запись треда, чат известен напрямую.
     * Увозит ближайший заход синка, как любой bot-message.
     */
    public function queueUnlinkedDmMessage(
        TelegramSupportChat $chat,
        string $text,
        ?int $replyToMsgId = null,
        string $via = 'helpdesk_unified_reply',
    ): ?TelegramSupportMessage {
        $accountId = $chat->messages()->max('telegram_support_account_id')
            ?? TelegramSupportAccount::query()->min('id');

        if (! $accountId) {
            return null;
        }

        return $this->createPendingOutgoing(
            (int) $accountId,
            $chat,
            $text,
            null,
            $replyToMsgId,
            $via,
            'ai',
        );
    }

    /**
     * Ответ в тред БЕЗ users-записи — техвопрос из Telegram-чата от непривязанного
     * автора. Отличие от {@see replyViaSupportChannel} только в том, откуда берётся
     * чат: там от пользователя, здесь от самого треда. Дальше всё то же — pending
     * outgoing, который увозит ближайший заход синка
     * ({@see PendingSupportReplyDrainer}).
     */
    public function replyToUnlinkedThread(SupportConversation $thread, string $text, ?User $curator): ?TelegramSupportMessage
    {
        if (! $thread->source_telegram_chat_id) {
            return null;
        }

        $chat = TelegramSupportChat::query()
            ->where('telegram_chat_id', (int) $thread->source_telegram_chat_id)
            ->first();

        if (! $chat) {
            return null;
        }

        $accountId = $chat->messages()->max('telegram_support_account_id')
            ?? TelegramSupportAccount::query()->min('id');

        if (! $accountId) {
            return null;
        }

        $message = $this->createPendingOutgoing(
            (int) $accountId,
            $chat,
            $text,
            $curator,
            $thread->source_telegram_message_id ? (int) $thread->source_telegram_message_id : null,
        );

        $this->conversations->attach($thread, $message, $message->sent_at);

        Log::info('SupportReplyService: ответ в тред без привязки записан', [
            'thread_id' => $thread->id,
            'chat_id' => $chat->telegram_chat_id,
            'message_id' => $message->id,
        ]);

        return $message;
    }

    /**
     * Ручной досыл зависшего ответа (кнопка «Дослать» в ленте хелпдеска).
     *
     * Ничего не отправляет сам и очередь не трогает: снимает пометку о провале и
     * обнуляет счётчик попыток, после чего сообщение снова попадает в выборку
     * PendingSupportReplyDrainer и уходит ближайшим заходом синка. Отправлять
     * отсюда нельзя — веб-процесс так же непригоден для MadelineProto, как и
     * воркер очереди.
     *
     * `pending_delivery` НЕ трогаем: снимает его только фактическая доставка.
     *
     * @return self::RESEND_* исход, который вызывающий превращает в уведомление
     */
    public function resendPendingDelivery(TelegramSupportMessage $message, ?User $curator = null): string
    {
        $payload = $message->raw_payload ?? [];

        if (! is_array($payload) || ! array_key_exists('pending_delivery', $payload)) {
            return self::RESEND_NOT_TRACKED;
        }

        if (! $payload['pending_delivery']) {
            // Лента отстаёт на такт поллинга, поэтому проверка обязана быть здесь,
            // а не в шаблоне: иначе досыл продублировал бы доставленный ответ.
            return self::RESEND_ALREADY_DELIVERED;
        }

        if (! config('services.telegram_support.enabled')) {
            // Перевзводить незачем: синк при выключенном юзерботе не запускается
            // вовсе, и сообщение просто лежало бы дальше. Куратору честнее
            // сказать, что досылать сейчас нечем.
            return self::RESEND_DISABLED;
        }

        $lastRetry = $payload['delivery_retry_at'] ?? null;
        if (is_string($lastRetry) && $lastRetry !== '') {
            try {
                if (Carbon::parse($lastRetry)->gt(now()->subSeconds(self::RESEND_THROTTLE_SECONDS))) {
                    return self::RESEND_THROTTLED;
                }
            } catch (\Throwable) {
                // Битую отметку игнорируем и досылаем.
            }
        }

        unset($payload['delivery_failed_at'], $payload['delivery_error']);
        // Счётчик попыток обнуляем — иначе перевзведённое сообщение сразу же
        // отсеялось бы дренажом как исчерпавшее лимит.
        $payload['delivery_attempts'] = 0;
        $payload['delivery_retry_at'] = now()->toIso8601String();
        $payload['delivery_retries'] = (int) ($payload['delivery_retries'] ?? 0) + 1;
        $payload['delivery_retried_by'] = $curator?->id;

        $message->forceFill(['raw_payload' => $payload])->save();

        Log::info('SupportReplyService: ручной досыл ответа', [
            'message_id' => $message->id,
            'chat_id' => $message->telegram_chat_id,
            'curator_id' => $curator?->id,
            'attempt' => $payload['delivery_retries'],
        ]);

        return self::RESEND_QUEUED;
    }

    private function resolveTargetChat(User $user, ?SupportConversation $thread): ?TelegramSupportChat
    {
        if ($thread?->source_telegram_chat_id) {
            $bySource = TelegramSupportChat::query()
                ->where('telegram_chat_id', (int) $thread->source_telegram_chat_id)
                ->first();
            if ($bySource) {
                return $bySource;
            }
        }

        $lastMsg = TelegramSupportMessage::query()
            ->with('chat')
            ->where('direction', 'incoming')
            ->where(function ($query) use ($user) {
                $query->whereHas('chat', fn ($q) => $q->where('linked_user_id', $user->id))
                    ->orWhereHas('contact', fn ($q) => $q->where('linked_user_id', $user->id));
            })
            ->orderByDesc('sent_at')
            ->first();

        if ($lastMsg?->chat) {
            return $lastMsg->chat;
        }

        return TelegramSupportChat::query()
            ->where('linked_user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->first();
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
        ?int $replyToMsgId = null,
        string $via = 'helpdesk_unified_reply',
        string $responderType = 'human',
    ): TelegramSupportMessage {
        $isAi = $responderType === 'ai';

        for ($attempt = 0; ; $attempt++) {
            try {
                return TelegramSupportMessage::create([
                    'telegram_support_account_id' => $accountId,
                    'telegram_support_chat_id' => $chat->id,
                    'telegram_chat_id' => $chat->telegram_chat_id,
                    'telegram_message_id' => $this->nextPendingMessageId($accountId, (int) $chat->telegram_chat_id),
                    'direction' => 'outgoing',
                    'role' => $isAi ? 'ai' : 'human',
                    'responder_type' => $isAi ? 'ai' : 'human',
                    'responder_user_id' => $isAi ? null : $curator?->id,
                    'ai_state' => $isAi ? 'sent' : null,
                    'text' => $text,
                    'raw_payload' => array_filter([
                        'pending_delivery' => true,
                        'via' => $via,
                        'reply_to_msg_id' => $replyToMsgId,
                    ], static fn ($v) => $v !== null),
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
