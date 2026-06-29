<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportAiReplyEvent extends Model
{
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
