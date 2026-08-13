<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GrammarLabPilotEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'participant_id',
        'event_type',
        'topic_id',
        'payload',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if ($row->created_at === null) {
                $row->created_at = now();
            }
        });
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(GrammarLabPilotParticipant::class, 'participant_id');
    }
}
