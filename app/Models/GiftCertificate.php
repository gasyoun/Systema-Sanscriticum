<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Подарочный сертификат (H3334): одноразовый код активации поверх тарифной
 * модели. Деньги — на платеже покупателя (tariff='gift'); здесь только
 * снимок подарка и хэш кода. Сырой код живёт ровно один проход — в письме
 * покупателю, и никогда не персистится и не логируется.
 */
class GiftCertificate extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACTIVATED = 'activated';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'payment_id',
        'course_id',
        'tariff_key',
        'tariff_title',
        'price',
        'start_block',
        'end_block',
        'code_hash',
        'code_hint',
        'number',
        'status',
        'activated_by_user_id',
        'activated_at',
        'recipient_payment_id',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'start_block' => 'integer',
        'end_block' => 'integer',
        'activated_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Что человек получит после активации, одним предложением. */
    public function grantsLabel(): string
    {
        return trim($this->tariff_title.(($this->course?->title ?? '') !== '' ? ' — '.$this->course->title : ''));
    }
}
