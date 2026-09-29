<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable delivery ledger for source-channel videos and their Story outcome. */
final class TelegramBusinessStoryPublication extends Model
{
    protected $fillable = [
        'source_chat_id', 'source_message_id', 'telegram_file_unique_id',
        'media_sha256', 'duplicate_of_id', 'story_id', 'story_ids', 'part_count',
        'cta_url', 'started_at', 'deferred_until', 'metrics', 'metrics_collected_at',
        'video_fingerprint', 'source_post', 'near_match_approved_at', 'status', 'error',
        'source_media_path', 'subtitle_draft', 'subtitle_status', 'subtitle_worker',
        'subtitle_requested_at', 'subtitle_deadline_at', 'subtitle_reviewed_at',
        'last_story_posted_at',
    ];

    protected $casts = [
        'source_message_id' => 'integer',
        'duplicate_of_id' => 'integer',
        'story_id' => 'integer',
        'story_ids' => 'array',
        'part_count' => 'integer',
        'started_at' => 'datetime',
        'deferred_until' => 'datetime',
        'metrics' => 'array',
        'metrics_collected_at' => 'datetime',
        'video_fingerprint' => 'array',
        'source_post' => 'array',
        'near_match_approved_at' => 'datetime',
        'subtitle_requested_at' => 'datetime',
        'subtitle_deadline_at' => 'datetime',
        'subtitle_reviewed_at' => 'datetime',
        'last_story_posted_at' => 'datetime',
    ];
}
