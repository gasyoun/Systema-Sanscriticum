<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Переход сделки между стадиями (GC-C1 / H1641). Append-only, без FK на deals —
 * зеркало LeadAudit: журнал переживает удаление родителя, поэтому связи могут
 * вернуть null.
 */
class DealTransition extends Model
{
    use HasFactory;

    /** Только момент записи; updated_at не нужен — журнал неизменяем. */
    public const UPDATED_AT = null;

    protected $fillable = [
        'deal_id',
        'from_stage_id',
        'to_stage_id',
        'user_id',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /** Сделка может быть уже удалена — связь без ограничений. */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function fromStage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'from_stage_id');
    }

    public function toStage(): BelongsTo
    {
        return $this->belongsTo(DealStage::class, 'to_stage_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Кто двинул сделку: менеджер или «Система» (мост от оплаты). */
    public function actorLabel(): string
    {
        return $this->user?->name ?? 'Система';
    }
}
