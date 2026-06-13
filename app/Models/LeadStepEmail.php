<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Факт отправки пошагового письма лиду (триггер — событие бота из n8n).
 * UNIQUE(lead_id, step) обеспечивает идемпотентность повторных вызовов.
 */
class LeadStepEmail extends Model
{
    protected $fillable = ['lead_id', 'step', 'sent_at'];

    protected $casts = ['sent_at' => 'datetime'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
