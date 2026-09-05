<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Обязательство перед владельцем (owner draw liability, H4188 п.3/4).
 * Остаток зафиксирован по февральской ноте книги (реестр 05-09-2026);
 * пара «выплачено / остаток» живёт на строке: paid — Σ append-only
 * выплат, remaining — хранимая проекция principal − paid.
 *
 * @property int $id
 * @property string $currency
 * @property numeric-string $principal
 * @property numeric-string $paid
 * @property string $remaining (accessor)
 * @property string|null $note
 */
class OwnerDrawLiability extends Model
{
    protected $fillable = [
        'currency',
        'principal',
        'paid',
        'fixed_at',
        'note',
    ];

    protected $casts = [
        'principal' => 'decimal:2',
        'paid' => 'decimal:2',
        'fixed_at' => 'date',
    ];

    /** Пара «выплачено / остаток» — остаток храним проекцией principal − paid. */
    public function getRemainingAttribute(): string
    {
        return bcsub((string) $this->principal, (string) $this->paid, 2);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(OwnerDrawPayment::class, 'owner_draw_liability_id');
    }

    /** Пересчитать пару после изменения выплат (вызывает OwnerDrawPayment-обсервер). */
    public function recalcPaid(): void
    {
        $this->forceFill([
            'paid' => $this->payments()->sum('amount'),
        ])->saveQuietly();
    }
}
