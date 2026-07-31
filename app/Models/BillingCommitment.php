<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingCommitmentKind;
use App\Enums\BillingCommitmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What we intend to collect under a subscription / consolidated bag (H2026 §4.3).
 * Successful charge fills payment_id and reuses the normal Payment access path.
 */
class BillingCommitment extends Model
{
    use HasFactory;

    protected $fillable = [
        'billing_subscription_id',
        'user_id',
        'kind',
        'course_id',
        'tariff_id',
        'payment_promise_id',
        'amount_rub',
        'covers_from',
        'covers_to',
        'access_key',
        'start_block',
        'end_block',
        'status',
        'due_on',
        'payment_id',
        'meta',
    ];

    protected $casts = [
        'kind' => BillingCommitmentKind::class,
        'status' => BillingCommitmentStatus::class,
        'amount_rub' => 'decimal:2',
        'covers_from' => 'date',
        'covers_to' => 'date',
        'due_on' => 'date',
        'start_block' => 'integer',
        'end_block' => 'integer',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            if ($row->status === null) {
                $row->status = BillingCommitmentStatus::Scheduled;
            }
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(BillingSubscription::class, 'billing_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function paymentPromise(): BelongsTo
    {
        return $this->belongsTo(PaymentPromise::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
