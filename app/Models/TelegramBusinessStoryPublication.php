<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable delivery ledger for source-channel videos and their Story outcome. */
final class TelegramBusinessStoryPublication extends Model
{
    protected $fillable = [
        'source_chat_id', 'source_message_id', 'telegram_file_unique_id',
        'media_sha256', 'duplicate_of_id', 'story_id', 'status', 'error',
    ];

    protected $casts = [
        'source_message_id' => 'integer',
        'duplicate_of_id' => 'integer',
        'story_id' => 'integer',
    ];
}
