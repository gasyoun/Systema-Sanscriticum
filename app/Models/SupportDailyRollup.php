<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Дневная аналитическая свёртка одного треда поддержки. С H1837 строка несёт
 * канал: `telegram` — импортированный TG-support-аккаунт (`telegram_support_chat_id`),
 * `web` — тред `SupportConversation` поверх `chat_messages` (веб-виджет и,
 * по `chat_messages.source`, VK/TG-student-bot, H1200). Ровно один из двух FK
 * заполнен.
 *
 * Потребители, которые умеют читать ТОЛЬКО Telegram-сторону (дашборд аналитики,
 * packet builder, наблюдаемость синка), обязаны скоупиться `->telegram()`:
 * у веб-строки нет `chat`, и обращение к `$rollup->chat->telegram_chat_id`
 * там фаталит. Кросс-канальные метрики дефлекции (`support:topic-ranking`,
 * `content:detect-gaps`) читают оба канала — в этом и смысл паритета.
 */
class SupportDailyRollup extends Model
{
    public const CHANNEL_TELEGRAM = 'telegram';

    public const CHANNEL_WEB = 'web';

    protected $fillable = [
        'channel',
        'telegram_support_chat_id',
        'support_conversation_id',
        'conversation_date',
        'first_message_at',
        'last_message_at',
        'incoming_count',
        'outgoing_count',
        'human_reply_count',
        'ai_suggested_count',
        'ai_sent_count',
        'is_unanswered',
        'has_new_contact',
        'first_response_seconds',
    ];

    protected $casts = [
        'conversation_date' => 'date',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
        'is_unanswered' => 'boolean',
        'has_new_contact' => 'boolean',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportChat::class, 'telegram_support_chat_id');
    }

    /** Веб-сторона: операционный тред, из которого свёрнута строка (channel=web). */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }

    public function scopeTelegram(Builder $query): Builder
    {
        return $query->where('channel', self::CHANNEL_TELEGRAM);
    }

    public function scopeWeb(Builder $query): Builder
    {
        return $query->where('channel', self::CHANNEL_WEB);
    }

    /** Фильтр по каналу; `null`/`all` — оба канала (кросс-канальная метрика). */
    public function scopeOfChannel(Builder $query, ?string $channel): Builder
    {
        return $channel === null || $channel === 'all'
            ? $query
            : $query->where('channel', $channel);
    }

    public function topicAssignments(): HasMany
    {
        return $this->hasMany(SupportTopicAssignment::class, 'support_daily_rollup_id');
    }
}
