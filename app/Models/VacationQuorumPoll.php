<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Опрос кворума «когда возобновляем занятия?» в чате каникульной группы
 * (H3790, фаза C). Один активный опрос на группу (outcome = pending).
 *
 * Голос = reply на message_id от платного участника группы (считает
 * VacationQuorumService::registerReply, льготники-бесплатники не считаются —
 * MG: кворум только платными, дефолт min_size ?? 4).
 */
class VacationQuorumPoll extends Model
{
    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_QUORUM_MET = 'quorum_met';

    public const OUTCOME_DISSOLVE_PENDING = 'dissolve_pending';

    public const OUTCOME_DISSOLVED = 'dissolved';

    public const OUTCOME_DECLINED = 'quorum_unmet_declined';

    public const OUTCOMES = [
        self::OUTCOME_PENDING => 'Ожидает дедлайна',
        self::OUTCOME_QUORUM_MET => 'Кворум собран',
        self::OUTCOME_DISSOLVE_PENDING => 'Ждёт одобрения распускания',
        self::OUTCOME_DISSOLVED => 'Группа распущена',
        self::OUTCOME_DECLINED => 'Кворум не собран (оставили)',
    ];

    protected $fillable = [
        'group_id', 'chat_id', 'message_id', 'asked_at', 'deadline_at',
        'resolved_at', 'outcome', 'paid_voters', 'quorum_required',
    ];

    protected $casts = [
        'asked_at' => 'datetime',
        'deadline_at' => 'datetime',
        'resolved_at' => 'datetime',
        'paid_voters' => 'array',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function isPending(): bool
    {
        return $this->outcome === self::OUTCOME_PENDING;
    }
}
