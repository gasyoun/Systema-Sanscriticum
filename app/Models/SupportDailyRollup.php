<?php

namespace App\Models;

use App\Support\UnifiedMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Дневной агрегат разговора поддержки: один субъект × одни сутки. НЕ операционный
 * тред (это {@see SupportConversation}) — см. docs/support-subsystem-map.md.
 *
 * С H1837 (S10) строка двухканальная: `channel` различает TG-support от веб-стороны
 * (веб-виджет / VK-бот / TG-student-бот), а субъект задаётся ровно одной из трёх
 * колонок — `telegram_support_chat_id` | `support_conversation_id` | `web_user_id`.
 */
class SupportDailyRollup extends Model
{
    /** Импортированный TG-support-аккаунт (userbot) — исторический единственный канал. */
    public const CHANNEL_TELEGRAM = UnifiedMessage::CHANNEL_TELEGRAM;

    /** Веб-виджет кабинета/сайта. */
    public const CHANNEL_WEB = UnifiedMessage::CHANNEL_WEB;

    public const CHANNEL_VK = UnifiedMessage::CHANNEL_VK;

    public const CHANNEL_TELEGRAM_BOT = UnifiedMessage::CHANNEL_TELEGRAM_BOT;

    public const CHANNEL_EMAIL = UnifiedMessage::CHANNEL_EMAIL;

    /** Каналы, живущие в `chat_messages` (агрегирует WebSupportRollupAggregator). */
    public const WEB_SIDE_CHANNELS = [
        self::CHANNEL_WEB,
        self::CHANNEL_VK,
        self::CHANNEL_TELEGRAM_BOT,
        self::CHANNEL_EMAIL,
    ];

    protected $fillable = [
        'channel',
        'telegram_support_chat_id',
        'support_conversation_id',
        'web_user_id',
        'conversation_date',
        'first_message_at',
        'last_message_at',
        'incoming_count',
        'outgoing_count',
        'human_reply_count',
        'ai_suggested_count',
        'ai_sent_count',
        'is_unanswered',
        'unresolved_after_hours',
        'has_new_contact',
        'first_response_seconds',
    ];

    /**
     * `channel` по умолчанию телеграмный — как и DEFAULT в миграции. Дублируется
     * здесь сознательно: до H1837 канала не было, и существующие места создают
     * строку без него (`SupportDailyRollup::create([...])`). Без этого дефолта
     * свежесозданный ОБЪЕКТ имел бы channel=null (Eloquent не перечитывает
     * DEFAULT из БД) и канал-зависимые ветки — например выбор хранилища в
     * SupportTopicClassifier — уводили бы телеграмную строку на веб-сторону.
     */
    protected $attributes = [
        'channel' => self::CHANNEL_TELEGRAM,
    ];

    protected $casts = [
        'conversation_date' => 'date',
        'first_message_at' => 'datetime',
        'last_message_at' => 'datetime',
        'is_unanswered' => 'boolean',
        'unresolved_after_hours' => 'boolean',
        'has_new_contact' => 'boolean',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportChat::class, 'telegram_support_chat_id');
    }

    /** Операционный тред веб-стороны (гость или залогиненный), если строка веб-канальная. */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'support_conversation_id');
    }

    /** Субъект веб-строки без треда (VK/TG-бот, ручные ответы, историческое). */
    public function webUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'web_user_id');
    }

    public function topicAssignments(): HasMany
    {
        return $this->hasMany(SupportTopicAssignment::class, 'support_daily_rollup_id');
    }

    public function scopeChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function scopeTelegramSide(Builder $query): Builder
    {
        return $query->where('channel', self::CHANNEL_TELEGRAM);
    }

    public function scopeWebSide(Builder $query): Builder
    {
        return $query->whereIn('channel', self::WEB_SIDE_CHANNELS);
    }

    public function isTelegramSide(): bool
    {
        return $this->channel === self::CHANNEL_TELEGRAM;
    }

    /** Русская подпись канала для дашбордов (та же вокабула, что в UnifiedMessage). */
    public function channelLabel(): string
    {
        return match ($this->channel) {
            self::CHANNEL_TELEGRAM => 'Telegram',
            self::CHANNEL_VK => 'ВКонтакте',
            self::CHANNEL_TELEGRAM_BOT => 'Telegram-бот',
            default => 'Кабинет',
        };
    }

    /**
     * Подпись субъекта, одинаково работающая для любого канала. До H1837 дашборды
     * ходили прямо в `$rollup->chat->…` — на веб-строке это был бы null-fatal.
     */
    public function subjectLabel(): string
    {
        if ($this->isTelegramSide()) {
            return $this->chat?->title
                ?? $this->chat?->linkedUser?->name
                ?? $this->chat?->username
                ?? 'Telegram chat '.($this->chat?->telegram_chat_id ?? '—');
        }

        if ($this->support_conversation_id !== null) {
            return $this->conversation?->displayName() ?? 'Тред #'.$this->support_conversation_id;
        }

        if ($this->web_user_id !== null) {
            return $this->webUser?->name ?? 'Пользователь #'.$this->web_user_id;
        }

        return 'Без атрибуции';
    }
}
