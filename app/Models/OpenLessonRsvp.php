<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ответ на карточку открытого занятия: going_at — «Я иду», declined_at — «Не смогу». */
class OpenLessonRsvp extends Model
{
    protected $fillable = [
        'schedule_id',
        'chat_id',
        'going_at',
        'declined_at',
        'zoom_sent_at',
    ];

    protected $casts = [
        'going_at' => 'datetime',
        'declined_at' => 'datetime',
        'zoom_sent_at' => 'datetime',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
