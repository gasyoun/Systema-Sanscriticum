<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Леджер exactly-once доставки недельного отчёта (H5709).
 *
 * Отделяем upsert агрегата от отправки: пересчёт снапшота той же недели
 * легален всегда, повторный ПОСТ — никогда без reconciliation. Клейм
 * (state=claimed) ставится ДО сетевого вызова; всё, что угодно после
 * попытки отправки и до подтверждения — unknown и чинится только через
 * --reconcile, слепой автоповтор запрещён спецификацией хендоффа.
 */
class SupportQuestionWeeklyDelivery extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_CLAIMED = 'claimed';

    public const STATE_UNKNOWN = 'unknown';

    public const STATE_ACKNOWLEDGED = 'acknowledged';

    public const STATE_NOT_DELIVERED = 'not_delivered';

    protected $fillable = [
        'week_start',
        'state',
        'claimed_at',
        'sent_at',
        'telegram_message_id',
        'suppress_reason',
        'reconciled_at',
        'meta',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
        'reconciled_at' => 'datetime',
        'meta' => 'array',
    ];
}
