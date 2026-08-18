<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна единица промоушенного каталога VisualDCS (корень / лемма / пассаж),
 * материализованная при импорте (H2869). Путь запроса читает эти строки и
 * никогда не открывает payload-файлы релиза.
 */
class VisualDcsUnit extends Model
{
    protected $table = 'visualdcs_units';

    protected $fillable = [
        'visualdcs_release_id',
        'surface',
        'unit_id',
        'tier',
        'title',
        'title_lower',
        'sort_order',
        'summary',
        'detail',
    ];

    protected $casts = [
        'summary' => 'array',
        'detail' => 'array',
    ];

    public function release(): BelongsTo
    {
        return $this->belongsTo(VisualDcsRelease::class, 'visualdcs_release_id');
    }
}
