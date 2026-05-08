<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PranaTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'amount',
        'reason',
        'source_type',
        'source_id',
        'meta',
    ];

    protected $casts = [
        'amount'     => 'integer',
        'meta'       => 'array',
        'created_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function reasonLabel(): string
    {
        return config('prana.reasons.' . $this->reason, $this->reason);
    }
}
