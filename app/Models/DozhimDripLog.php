<?php

declare(strict_types=1);

namespace App\Models;

use App\Console\Commands\DozhimDripCommand;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Отметка «шаг дожим-дрипа отправлен» (H2059) — идемпотентность
 * `dozhim:drip` по паре (deal_id, step). См. {@see DozhimDripCommand}.
 */
class DozhimDripLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'deal_id',
        'step',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }
}
