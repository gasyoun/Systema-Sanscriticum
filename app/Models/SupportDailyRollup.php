<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportDailyRollup extends Model
{
    protected $fillable = [
        'telegram_support_chat_id',
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

    public function topicAssignments(): HasMany
    {
        return $this->hasMany(SupportTopicAssignment::class, 'support_daily_rollup_id');
    }
}
