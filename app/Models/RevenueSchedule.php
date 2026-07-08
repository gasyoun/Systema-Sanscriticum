<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка графика признания выручки (accrual): доля суммы платежа, признаваемая
 * в календарном месяце period_month ('YYYY-MM'). Субледжер поверх Payment —
 * пересобирается идемпотентно RevenueScheduleService, руками не редактируется.
 *
 * @property int $payment_id
 * @property string $period_month
 * @property float $amount
 */
class RevenueSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'period_month',
        'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
