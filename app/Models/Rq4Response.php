<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Rq4Response extends Model
{
    public const PHASE_PRE_TEST = 'pre_test';

    public const PHASE_POST_TEST = 'post_test';

    public const PHASE_RETENTION_TEST = 'retention_test';

    protected $fillable = [
        'participant_id',
        'phase',
        'item_slp1',
        'answered_ryad',
        'answered_tip',
        'answered_set',
        'correct',
        'answered_at',
    ];

    protected $casts = [
        'correct' => 'boolean',
        'answered_at' => 'datetime',
    ];

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Rq4Participant::class, 'participant_id');
    }
}
