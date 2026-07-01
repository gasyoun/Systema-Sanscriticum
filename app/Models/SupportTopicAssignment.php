<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportTopicAssignment extends Model
{
    protected $fillable = [
        'support_daily_rollup_id',
        'category',
        'source',
        'confidence',
        'reason',
    ];

    protected $casts = [
        'confidence' => 'float',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportDailyRollup::class, 'support_daily_rollup_id');
    }
}
