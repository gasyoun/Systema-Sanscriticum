<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H6343: дедуп-метка волны анонса возобновления (t7/t1/manual) или явка-алерта
 * после каникул. Уникальный ключ (group_id, phase, schedule_id).
 */
class VacationResumeNotice extends Model
{
    public const PHASE_T7 = 't7';

    public const PHASE_T1 = 't1';

    public const PHASE_MANUAL = 'manual';

    public const PHASE_ATTENDANCE = 'attendance_alert';

    protected $fillable = [
        'group_id',
        'phase',
        'schedule_id',
        'recipients',
        'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
