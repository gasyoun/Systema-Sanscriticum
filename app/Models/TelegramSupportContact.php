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

    protected static function booted(): void
    {
        static::saved(function (self $contact): void {
            if (! $contact->linked_user_id || ! $contact->telegram_support_chat_id) {
                return;
            }

            $chat = $contact->chat;
            // Multi-user groups: не перетирать chat.linked_user_id последним отправителем.
            // Линк sender→User живёт на contact; chat.linked_user_id — только private.
            if ($chat
                && ($chat->type === null || $chat->type === 'private')
                && $chat->linked_user_id !== $contact->linked_user_id) {
                $chat->forceFill(['linked_user_id' => $contact->linked_user_id])->save();
            }
        });
    }

    public function chat(): BelongsTo
    {
        return $this->belongsTo(TelegramSupportChat::class, 'telegram_support_chat_id');
    }

    public function linkedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_user_id');
    }
}
