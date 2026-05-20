<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentPromise extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'course_id',
        'promised_at',
        'amount',
        'status',
        'note',
        'fulfilled_at',
        'cancelled_at',
        'fulfilled_payment_id',
    ];

    protected $casts = [
        'promised_at' => 'date',
        'amount' => 'decimal:2',
        'fulfilled_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function fulfilledPayment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'fulfilled_payment_id');
    }

    /** Активные обещания: дата ещё не прошла. */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereDate('promised_at', '>=', now()->toDateString());
    }

    /** Просроченные обещания: status=active, но дата прошла. */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->where('status', self::STATUS_ACTIVE)
            ->whereDate('promised_at', '<', now()->toDateString());
    }

    public function scopeForPair(Builder $query, int $userId, int $courseId): Builder
    {
        return $query->where('user_id', $userId)->where('course_id', $courseId);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->promised_at !== null
            && $this->promised_at->lt(now()->startOfDay());
    }
}
