<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramSupportAccount extends Model
{
    protected $fillable = [
        'name',
        'session_path',
        'phone',
        'api_id',
        'is_enabled',
        'auto_reply_enabled',
        'hint_recipients',
        'last_synced_at',
        'sync_state',
        'last_successful_sync_at',
        'last_sync_error',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'auto_reply_enabled' => 'boolean',
        'hint_recipients' => 'array',
        'last_synced_at' => 'datetime',
        'sync_state' => 'array',
        'last_successful_sync_at' => 'datetime',
    ];
}
