<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingAmountPolicy;
use App\Enums\BillingSubscriptionMode;
use App\Enums\BillingSubscriptionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One row ↔ one Tochka (or future PayPal) subscription operation (H2026 §4.2).
 * No live bank calls in Phase 0; provider_subscription_id stays null until Phase 1.
 */
class BillingSubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'mode',
        'provider',
        'provider_subscription_id',
        'status',
        'period',
        'tranche_count',
        'amount_rub',
        'amount_policy',
        'anchor_date',
        'course_id',
        'installment_group_id',
        'club_product_id',
        'feature_flag_snapshot',
        'meta',
    ];

    protected $casts = [
        'mode' => BillingSubscriptionMode::class,
        'status' => BillingSubscriptionStatus::class,
        'amount_policy' => BillingAmountPolicy::class,
        'amount_rub' => 'decimal:2',
        'tranche_count' => 'integer',
        'anchor_date' => 'date',
        'feature_flag_snapshot' => 'array',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if (empty($row->uuid)) {
                $row->uuid = (string) Str::uuid();
            }
            if (empty($row->provider)) {
                $row->provider = 'tochka';
            }
            if ($row->status === null) {
                $row->status = BillingSubscriptionStatus::PendingFirstPay;
            }
            if ($row->amount_policy === null) {
                $row->amount_policy = BillingAmountPolicy::Fixed;
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function commitments(): HasMany
    {
        return $this->hasMany(BillingCommitment::class);
    }
}
