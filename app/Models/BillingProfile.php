<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingConsolidatedCadence;
use App\Enums\BillingPreferredMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-user billing preference for Tochka recurring (H2026 §4.1).
 * Scaffold only until features.tochka_recurring is enabled.
 */
class BillingProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'preferred_mode',
        'consolidated_cadence',
        'tochka_consumer_id',
        'default_charge_day',
    ];

    protected $casts = [
        'preferred_mode' => BillingPreferredMode::class,
        'consolidated_cadence' => BillingConsolidatedCadence::class,
        'default_charge_day' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class, 'user_id', 'user_id');
    }
}
