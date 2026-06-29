<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramSupportContact extends Model
{
    protected $fillable = [
        'telegram_user_id',
        'telegram_support_chat_id',
        'linked_user_id',
        'name',
        'username',
        'first_seen_at',
        'first_inbound_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'first_inbound_at' => 'datetime',
    ];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportChat::class, 'telegram_support_chat_id');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
