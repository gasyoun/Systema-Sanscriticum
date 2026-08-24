<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка публичной благодарности меценату (план института N3).
 *
 * Инварианты:
 *  - имя публикуется только по явному согласию донора (онлайн: чекбокс
 *    на /mecenaty; офлайн: ручная запись администратором);
 *  - суммы в таблице нет намеренно — рулинг MG 23-08 (см. миграцию);
 *  - payment_id уникален → повторный paid-вебхук не плодит строк.
 */
class DonationGratitude extends Model
{
    protected $fillable = [
        'payment_id',
        'name_display',
        'is_public',
        'show_amount',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'show_amount' => 'boolean',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** Публичный список для страницы /mecenaty — старейшие первыми. */
    public function scopePublicList($query)
    {
        return $query->where('is_public', true)->orderBy('created_at')->orderBy('id');
    }
}
