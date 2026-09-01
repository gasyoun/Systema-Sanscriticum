<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H3821 — published fixed EUR/USD price for a tariff, refreshed monthly by
 * paypal:refresh-foreign-prices. price already bakes in the 8% markup (see
 * PaypalForeignPriceService); it does NOT reflect a per-student discount —
 * discounted seats are computed live at checkout time instead.
 */
class TariffForeignPrice extends Model
{
    use HasFactory;

    protected $fillable = [
        'tariff_id',
        'currency',
        'price',
        'fx_rate',
        'computed_at',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'fx_rate' => 'decimal:4',
        'computed_at' => 'datetime',
    ];

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }
}
