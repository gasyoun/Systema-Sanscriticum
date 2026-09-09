<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * H4434 — дедуп-леджер DST-напоминаний: одна строка на (user, переход, stage).
 * Уникальный индекс из миграции защищает от повторной отправки на каждом
 * запуске ежедневного скана.
 */
class TzAlertSent extends Model
{
    public const STAGE_D7 = 'd7';

    public const STAGE_D1_EVENING = 'd1_evening';

    public const STAGE_D1_HOUR = 'd1_hour';

    protected $fillable = ['user_id', 'transition_date', 'stage'];

    protected $casts = [
        'transition_date' => 'date',
        'sent_at' => 'datetime',
    ];
}
