<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H3462: одно принятое по вебхуку входящее письмо канала email
 * (zabota@samskrte.ru). Само сообщение поддержки живёт в chat_messages
 * (source='email') — эта строка лишь приёмная квитанция: ключ дедупа
 * (message_id) и след судьбы письма (queued → ingested).
 */
class InboundEmail extends Model
{
    /** Отправитель не совпал с users.email — ждёт ручной привязки оператором. */
    public const STATUS_QUEUED = 'queued';

    /** Привязано к пользователю и записано в chat_messages. */
    public const STATUS_INGESTED = 'ingested';

    protected $fillable = [
        'message_id',
        'from_email',
        'from_name',
        'subject',
        'text',
        'status',
        'user_id',
        'chat_message_id',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }
}
