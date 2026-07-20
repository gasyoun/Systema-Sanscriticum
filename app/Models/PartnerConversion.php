<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Аудит партнёрского вознаграждения: одна строка на приведённого клиента
 * (unique user_id — идемпотентность, ровно одно вознаграждение за клиента).
 * Начисляется при ПЕРВОЙ реальной оплате курса приведённым клиентом; статус
 * accrued → paid_out проставляет администратор при фактической выплате.
 */
class PartnerConversion extends Model
{
    use HasFactory;

    public const STATUS_ACCRUED = 'accrued';

    public const STATUS_PAID_OUT = 'paid_out';

    public const STATUSES = [
        self::STATUS_ACCRUED => 'Начислено (к выплате)',
        self::STATUS_PAID_OUT => 'Выплачено',
    ];

    protected $fillable = [
        'partner_id',
        'user_id',
        'payment_id',
        'reward_amount',
        'status',
        'paid_out_at',
    ];

    protected $casts = [
        'reward_amount' => 'decimal:2',
        'paid_out_at' => 'datetime',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    /** Приведённый клиент, за которого начислено вознаграждение. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
