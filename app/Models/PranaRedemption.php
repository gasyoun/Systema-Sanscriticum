<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Покупка перка студентом за прану. Прана списана при создании; админ исполняет
 * перк вручную (status pending → fulfilled). perk_name/cost — снимок на момент покупки.
 */
class PranaRedemption extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_FULFILLED = 'fulfilled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'user_id',
        'prana_perk_id',
        'perk_name',
        'cost',
        'status',
        'admin_note',
    ];

    protected $casts = [
        'cost' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function perk(): BelongsTo
    {
        return $this->belongsTo(PranaPerk::class, 'prana_perk_id');
    }
}
