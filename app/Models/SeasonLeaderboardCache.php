<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonLeaderboardCache extends Model
{
    use HasFactory;

    protected $table = 'season_leaderboard_cache';

    public $timestamps = false;

    protected $fillable = [
        'season_id',
        'user_id',
        'baseline_lifetime_prana',
        'prana_earned',
        'rank_position',
        'computed_at',
    ];

    protected $casts = [
        'computed_at' => 'datetime',
    ];

    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
