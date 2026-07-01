<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TelegramSupportChat extends Model
{
    protected $fillable = [
        'telegram_chat_id',
        'linked_user_id',
        'type',
        'title',
        'username',
        'first_seen_at',
        'last_message_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TelegramSupportMessage::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(SupportDailyRollup::class);
    }
}
