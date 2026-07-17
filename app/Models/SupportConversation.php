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
        'status',
        'subject',
        'assigned_to',
        'last_message_at',
        'closed_at',
    ];

    protected $casts = [
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
        'visitor_geo_resolved_at' => 'datetime',
    ];

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
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
