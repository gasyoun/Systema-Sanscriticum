<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pending «student asks for payment deferral» draft from chat/TG detectors.
 * Does nothing until a curator approves → PaymentPromise (H2156).
 */
class PaymentPromiseSuggestion extends Model
{
    public const SOURCE_CHAT_MESSAGE = 'chat_message';

    public const SOURCE_TELEGRAM_SUPPORT_MESSAGE = 'telegram_support_message';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_DISMISSED = 'dismissed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'course_id',
        'source_type',
        'source_id',
        'detected_text',
        'suggested_date',
        'suggested_amount',
        'candidate_course_ids',
        'confidence',
        'status',
        'resolved_by',
        'resolved_at',
        'payment_promise_id',
    ];

    protected $casts = [
        'suggested_date' => 'date',
        'suggested_amount' => 'decimal:2',
        'candidate_course_ids' => 'array',
        'confidence' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function paymentPromise(): BelongsTo
    {
        return $this->belongsTo(PaymentPromise::class);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
