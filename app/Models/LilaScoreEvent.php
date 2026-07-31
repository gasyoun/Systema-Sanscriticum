<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Authenticated /lila score events (H2052). Separate from anonymous game_events
 * so the funnel table stays outside the 152-FZ perimeter.
 */
class LilaScoreEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'drill',
        'band',
        'points',
        'event',
        'created_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
