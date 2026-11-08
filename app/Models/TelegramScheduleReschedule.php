<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TelegramScheduleReschedule extends Model
{
    public const STATUS_APPLIED = 'applied';

    public const STATUS_UNDONE = 'undone';

    protected $fillable = [
        'telegram_chat_id',
        'telegram_message_id',
        'schedule_id',
        'group_id',
        'actor_telegram_id',
        'old_start',
        'old_end',
        'new_start',
        'new_end',
        'receipt_message_id',
        'status',
        'undo_expires_at',
    ];

    protected $casts = [
        'old_start' => 'datetime',
        'old_end' => 'datetime',
        'new_start' => 'datetime',
        'new_end' => 'datetime',
        'undo_expires_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
