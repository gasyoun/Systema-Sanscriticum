<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeasonReward extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'user_id',
        'position',
        'reward_type',
        'reward_value',
        'awarded_at',
        'claimed_at',
    ];

    protected $casts = [
        'awarded_at' => 'datetime',
        'claimed_at' => 'datetime',
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
