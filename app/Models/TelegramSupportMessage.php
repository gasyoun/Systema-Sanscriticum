<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramSupportMessage extends Model
{
    protected $fillable = [
        'support_conversation_id',
        'telegram_support_account_id',
        'telegram_support_chat_id',
        'telegram_support_contact_id',
        'telegram_chat_id',
        'telegram_message_id',
        'direction',
        'role',
        'responder_type',
        'responder_user_id',
        'responder_marker',
        'ai_state',
        'text',
        'raw_payload',
        'sent_at',
    ];

    protected $casts = [
        'raw_payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportChat::class, 'telegram_support_chat_id');
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportContact::class, 'telegram_support_contact_id');
    }

    public function responder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responder_user_id');
    }
}
