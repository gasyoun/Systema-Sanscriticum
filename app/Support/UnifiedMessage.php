<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ChatMessage;
use App\Models\TelegramSupportMessage;
use Illuminate\Support\Carbon;

/**
 * Канал-независимое представление одного сообщения поддержки. Read-only слой над
 * двумя разными хранилищами (см. docs/support-subsystem-map.md):
 *   - ChatMessage (веб-чат кабинета): направление/тип отправителя ВЫВОДИМ из role;
 *   - TelegramSupportMessage (импорт TG-аккаунта): те же поля хранятся явно.
 * Таблицы намеренно НЕ сливаются — это общая форма чтения поверх обеих.
 */
final class UnifiedMessage
{
    public const CHANNEL_WEB = 'web';

    public const CHANNEL_TELEGRAM = 'telegram';

    /** VK-бот (H1200, Jivo-паритет S5) — chat_messages.source = 'vk'. */
    public const CHANNEL_VK = 'vk';

    /**
     * TG-student-bot (H1200, Jivo-паритет S5) — chat_messages.source =
     * 'telegram_bot'. НЕ путать с CHANNEL_TELEGRAM — тот импортированный
     * TG-support-аккаунт (другое хранилище, TelegramSupportMessage).
     */
    public const CHANNEL_TELEGRAM_BOT = 'telegram_bot';

    /**
     * Входящий email (H3462, Jivo-паритет S5 остаток) — chat_messages.source =
     * 'email'; приём через вебхук zabota@samskrte.ru (InboundEmailIngester).
     */
    public const CHANNEL_EMAIL = 'email';

    public const DIRECTION_INCOMING = 'incoming';

    public const DIRECTION_OUTGOING = 'outgoing';

    /** Тип отправителя исходящего сообщения. */
    public const RESPONDER_AI = 'ai';

    public const RESPONDER_HUMAN = 'human';

    public function __construct(
        public readonly string $channel,
        public readonly int $sourceId,
        public readonly ?int $userId,
        public readonly string $direction,
        public readonly ?string $responderType,
        public readonly ?int $responderUserId,
        public readonly ?string $responderName,
        public readonly ?string $aiState,
        public readonly string $text,
        public readonly Carbon $sentAt,
        public readonly bool $isRead,
        /**
         * Подпись-маркер отвечавшего, если канал её носит (TG-support кладёт
         * favicon-маркер в responder_marker). Нужна как последний fallback имени
         * в дашбордах, когда маркер ещё не смаплен в SupportResponderMapping.
         * У веб-стороны такого поля нет — там всегда null.
         */
        public readonly ?string $responderMarker = null,
        /**
         * Состояние доставки исходящего ответа куратора — только у TG-support и
         * только у тех сообщений, которые отправлял хелпдеск (см.
         * SupportDeliveryStatus::fromRawPayload). null означает «доставку этого
         * сообщения мы не отслеживаем», и это НЕ синоним «доставлено»: у
         * chat_messages статуса доставки нет вовсе, поэтому зелёная галочка там
         * была бы выдумкой — ровно того же сорта, что здесь и чинится.
         */
        public readonly ?SupportDeliveryStatus $delivery = null,
    ) {}

    public function isIncoming(): bool
    {
        return $this->direction === self::DIRECTION_INCOMING;
    }

    /** Человеческий (не студент, не ИИ) исходящий ответ. */
    public function isHumanReply(): bool
    {
        return ! $this->isIncoming() && $this->responderType !== self::RESPONDER_AI;
    }

    /** Подпись отправителя для UI. */
    public function senderLabel(): string
    {
        if ($this->isIncoming()) {
            return 'Студент';
        }

        if ($this->responderType === self::RESPONDER_AI) {
            return 'ИИ-Куратор';
        }

        return $this->responderName ?? 'Куратор';
    }

    /** CSS-класс пузыря (совместим с версткой helpdesk). */
    public function bubbleClass(): string
    {
        if ($this->isIncoming()) {
            return 'user-bubble';
        }

        return $this->responderType === self::RESPONDER_AI ? 'bot-bubble' : 'curator-bubble';
    }

    /** Сторона пузыря: человеческий ответ справа, остальное слева. */
    public function wrapperClass(): string
    {
        return $this->isHumanReply() ? 'curator' : 'user';
    }

    public function channelLabel(): string
    {
        return match ($this->channel) {
            self::CHANNEL_TELEGRAM => 'Telegram',
            self::CHANNEL_VK => 'ВКонтакте',
            self::CHANNEL_TELEGRAM_BOT => 'Telegram-бот',
            self::CHANNEL_EMAIL => 'Email',
            default => 'Кабинет',
        };
    }

    public function htmlText(): string
    {
        return SupportText::safeHtml($this->text);
    }

    /**
     * Веб-чат + VK-бот + TG-student-bot — все пишут в chat_messages одинаковой
     * формой; `source` (H1200) различает канал, NULL == веб-виджет (старые
     * строки/виджет-контроллер source не проставляет). direction и
     * responderType выводятся из role — единственного источника правды на
     * веб-стороне (user=входящее; bot=ИИ; curator=человек) — так же для
     * VK/TG-bot сообщений (тот же role-конвейер).
     */
    public static function fromChatMessage(ChatMessage $message): self
    {
        $incoming = $message->role === 'user';

        $responderType = match ($message->role) {
            'bot' => self::RESPONDER_AI,
            'curator' => self::RESPONDER_HUMAN,
            default => null,
        };

        $channel = match ($message->source) {
            'vk' => self::CHANNEL_VK,
            'telegram_bot' => self::CHANNEL_TELEGRAM_BOT,
            'email' => self::CHANNEL_EMAIL,
            default => self::CHANNEL_WEB,
        };

        return new self(
            channel: $channel,
            sourceId: $message->id,
            userId: $message->user_id,
            direction: $incoming ? self::DIRECTION_INCOMING : self::DIRECTION_OUTGOING,
            responderType: $responderType,
            responderUserId: $message->answered_by,
            responderName: $message->answeredBy?->name,
            aiState: $message->ai_state,
            text: (string) $message->text,
            sentAt: $message->created_at,
            isRead: (bool) $message->is_read,
        );
    }

    /**
     * Импортированный TG-support. Личность берём из связанного чата/контакта;
     * прочитанность на TG-стороне не отслеживается → считаем прочитанным.
     */
    public static function fromTelegramSupportMessage(TelegramSupportMessage $message): self
    {
        $userId = $message->chat?->linked_user_id
            ?? $message->contact?->linked_user_id;

        return new self(
            channel: self::CHANNEL_TELEGRAM,
            sourceId: $message->id,
            userId: $userId,
            direction: $message->direction,
            responderType: $message->responder_type,
            responderUserId: $message->responder_user_id,
            responderName: $message->responder?->name,
            aiState: $message->ai_state,
            text: (string) $message->text,
            sentAt: $message->sent_at,
            isRead: true,
            responderMarker: $message->responder_marker,
            delivery: $message->direction === self::DIRECTION_OUTGOING
                ? SupportDeliveryStatus::fromRawPayload($message->raw_payload, $message->sent_at)
                : null,
        );
    }
}
