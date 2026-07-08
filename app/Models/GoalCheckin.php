<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Один «standup»-снимок прогресса цели (H376) — записан вручную со страницы
 * или еженедельной командой goals:record-checkins.
 */
class GoalCheckin extends Model
{
    protected $fillable = [
        'goal_id',
        'value_at_checkin',
        'level',
        'note',
        'checked_in_at',
    ];

    protected $casts = [
        'value_at_checkin' => 'decimal:2',
        'checked_in_at' => 'datetime',
    ];

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }
}
