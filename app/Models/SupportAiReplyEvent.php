<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAiReplyEvent extends Model
{
    /** H3395: куратор отправил ответ, начатый с шаблона библиотеки (Helpdesk). */
    public const EVENT_TEMPLATE_USED = 'template_used';

    /** H3234 (этап 5): теневой ответ локальной модели (qwen3:14b) рядом с онлайн-логом OpenRouter. */
    public const EVENT_OLLAMA_SHADOW = 'ollama_shadow';

    protected $fillable = [
        'telegram_support_message_id',
        'event_type',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportMessage::class, 'telegram_support_message_id');
    }
}
