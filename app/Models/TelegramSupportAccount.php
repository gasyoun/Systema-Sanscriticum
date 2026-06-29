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
        'last_synced_at',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'last_synced_at' => 'datetime',
    ];
}
