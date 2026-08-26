<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H3532 — ручной снапшот финансового показателя (баланс PayPal, курс €).
 * Единственная таблица, куда пишет годовой календарь выплат;
 * teacher_payouts / payments /_users остаются read-only навсегда.
 *
 * amount_minor — минорные единицы (евроценты/копейки): 1250.00 € → 125000.
 */
class FinanceSnapshot extends Model
{
    public const TYPE_PAYPAL_BALANCE = 'paypal_balance';

    public const TYPE_FX_EUR_RUB = 'fx_eur_rub';

    protected $fillable = ['type', 'amount_minor', 'currency', 'entered_at', 'user_id', 'note'];

    protected $casts = [
        'amount_minor' => 'integer',
        'entered_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Последний снапшот данного типа (самая поздняя дата ввода). */
    public static function latestOfType(string $type): ?self
    {
        /** @var self|null $row */
        $row = static::query()
            ->where('type', $type)
            ->orderByDesc('entered_at')
            ->orderByDesc('id')
            ->first();

        return $row;
    }

    /** Основные единицы (float) из минорных. */
    public function majorAmount(): float
    {
        return round($this->amount_minor / 100, 2);
    }

    /** Из основных единиц в минорные (детерминированное округление). */
    public static function toMinor(float $major): int
    {
        return (int) round($major * 100);
    }
}
