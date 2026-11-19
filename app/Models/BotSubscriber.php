<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Чат, нажавший /start в @samskrte_bot (или лид магнита) — аудитория карточек
 * открытых занятий. join_* — путь «✍️ Участвовать»: шаг, ответы анкеты, лид.
 */
class BotSubscriber extends Model
{
    public const SOURCE_LEAD = 'lead';

    public const SOURCE_START = 'start';

    public const STEP_SUBSCRIBE = 'subscribe';

    public const STEP_NAME = 'name';

    public const STEP_INTEREST = 'interest';

    public const STEP_TIME = 'time';

    public const STEP_DONE = 'done';

    protected $fillable = [
        'chat_id',
        'source',
        'first_name',
        'username',
        'menu_sent_at',
        'blocked_at',
        'join_step',
        'join_name',
        'join_interest',
        'join_time',
        'consent_at',
        'joined_at',
        'lead_id',
    ];

    protected $casts = [
        'menu_sent_at' => 'datetime',
        'blocked_at' => 'datetime',
        'consent_at' => 'datetime',
        'joined_at' => 'datetime',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('blocked_at');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }
}
