<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Операционный тред поддержки: reopenable «дело» по одному пользователю поверх
 * обоих каналов. НЕ путать с `SupportDailyRollup` (дневная аналитика). Группирует
 * сообщения через nullable FK на chat_messages и telegram_support_messages.
 * См. docs/support-subsystem-map.md.
 */
class SupportConversation extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_PENDING = 'pending';

    public const STATUS_CLOSED = 'closed';

    /** Ops-очередь: тех. вопросы из userbot (ЛС/учебные группы). */
    public const QUEUE_TECHNICAL = 'technical';

    public const QUEUE_GENERAL = 'general';

    protected $fillable = [
        'user_id',
        'guest_token',
        'guest_name',
        'visitor_ip',
        'visitor_city',
        'visitor_region',
        'visitor_country',
        'visitor_geo_resolved_at',
        'entry_url',
        'referrer',
        'lead_id',
        'contact_email',
        'contact_phone',
        'lead_captured_at',
        'status',
        'queue',
        'subject',
        'assigned_to',
        'source_telegram_chat_id',
        'source_telegram_user_id',
        'source_telegram_message_id',
        'source_chat_type',
        'last_message_at',
        'closed_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
        'visitor_geo_resolved_at' => 'datetime',
        'lead_captured_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    public function isTechnical(): bool
    {
        return $this->queue === self::QUEUE_TECHNICAL;
    }

    /** Тред анонимного веб-посетителя (нет user_id, есть session-токен, H536). */
    public function isGuest(): bool
    {
        return $this->user_id === null && $this->guest_token !== null;
    }

    /** Подпись для оператора в Helpdesk: имя пользователя или «Гость #id». */
    public function displayName(): string
    {
        if ($this->user_id !== null) {
            return $this->user?->name ?? "Пользователь #{$this->user_id}";
        }

        return $this->guest_name ?: 'Гость #'.$this->id;
    }

    /**
     * Гео-подпись посетителя для Helpdesk (H1196, Jivo-паритет Pillar 1):
     * «Город, Страна» из того, что разрешил VisitorGeoResolver. Пусто → null
     * (город не резолвился: флаг OFF, драйвер null, приватный IP или промах).
     */
    public function locationLabel(): ?string
    {
        $parts = array_filter([$this->visitor_city, $this->visitor_country]);

        return $parts === [] ? null : implode(', ', $parts);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** CRM-строка лида, записанная S4-захватом (H1199), если посетитель оставил контакт. */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function chatMessages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function telegramMessages(): HasMany
    {
        return $this->hasMany(TelegramSupportMessage::class);
    }
}
