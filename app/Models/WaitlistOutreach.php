<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одна рассылка статуса листу ожидания группы (H3327). Append-only аудит:
 * куратор/админ видят, что и кому уже отправлено; авто-напоминание за 2 дня
 * дедуплицируется по строке kind=auto_reminder за сегодня.
 */
class WaitlistOutreach extends Model
{
    public const KIND_MANUAL = 'manual';

    public const KIND_TRANSFER = 'transfer';

    public const KIND_AUTO_REMINDER = 'auto_reminder';

    protected $fillable = [
        'group_id',
        'kind',
        'text',
        'actor_id',
        'messengers_count',
        'manual_count',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
