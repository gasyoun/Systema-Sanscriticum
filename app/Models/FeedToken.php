<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Отзывной токен персонального iCal/webcal-фида (Google Calendar Phase 1).
 */
class FeedToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'token',
        'revoked_at',
    ];

    protected $casts = [
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public static function generate(): string
    {
        return Str::random(48);
    }
}
