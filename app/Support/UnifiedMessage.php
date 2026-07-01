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
        public readonly ?string $aiState,
        public readonly string $text,
        public readonly Carbon $sentAt,
        public readonly bool $isRead,
    ) {}

    /**
     * Веб-чат. direction и responderType выводятся из role — единственного
     * источника правды на веб-стороне (user=входящее; bot=ИИ; curator=человек).
     */
    public static function fromChatMessage(ChatMessage $message): self
    {
        $incoming = $message->role === 'user';

        $responderType = match ($message->role) {
            'bot' => self::RESPONDER_AI,
            'curator' => self::RESPONDER_HUMAN,
            default => null,
        };

        return new self(
            channel: self::CHANNEL_WEB,
            sourceId: $message->id,
            userId: $message->user_id,
            direction: $incoming ? self::DIRECTION_INCOMING : self::DIRECTION_OUTGOING,
            responderType: $responderType,
            responderUserId: $message->answered_by,
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
            aiState: $message->ai_state,
            text: (string) $message->text,
            sentAt: $message->sent_at,
            isRead: true,
        );
    }
}
