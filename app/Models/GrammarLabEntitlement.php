<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class GrammarLabEntitlement extends Model
{
    protected $fillable = [
        'user_id',
        'grant_kind',
        'source_ref',
        'starts_at',
        'ends_at',
        'revoked_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(?Carbon $at = null): bool
    {
        $at ??= now();
        if ($this->revoked_at !== null) {
            return false;
        }
        if ($this->starts_at !== null && $this->starts_at->gt($at)) {
            return false;
        }
        if ($this->ends_at !== null && $this->ends_at->lte($at)) {
            return false;
        }

        return true;
    }
}
