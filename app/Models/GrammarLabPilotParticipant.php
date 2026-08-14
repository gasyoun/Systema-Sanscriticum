<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GrammarLabPilotParticipant extends Model
{
    public const EXPOSURE_LEVELS = ['none', 'kochergina', 'beyond'];

    protected $fillable = [
        'user_id',
        'prior_exposure',
        'cohort',
        'consented_at',
        'withdrawn_at',
    ];

    protected $casts = [
        'consented_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(GrammarLabPilotEvent::class, 'participant_id');
    }
}
