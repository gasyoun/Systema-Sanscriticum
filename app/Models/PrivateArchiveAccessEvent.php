<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PrivateArchiveAccessEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'archive_key', 'action', 'reason', 'request_hash', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];
}
