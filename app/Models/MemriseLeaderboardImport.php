<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemriseLeaderboardImport extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'course_id',
        'username',
        'points',
        'period',
        'rank',
        'user_id',
        'imported_at',
    ];

    protected $casts = [
        'points' => 'integer',
        'rank' => 'integer',
        'imported_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
