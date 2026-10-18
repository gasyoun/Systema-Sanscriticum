<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * H5823 — одна ушедшая стадия напоминания о продлении.
 *
 * Дедуп-ключ: (surface, subject_id, stage, period_ends_at). Дата конца
 * периода в ключе обязательна для upsert-поверхностей
 * (course_access_windows: продление двигает ends_at у ОДНОЙ строки —
 * без неё новый срок навсегда остался бы «уже напомненным»).
 *
 * Читатели: демон напоминаний (дедуп) и кабинет/отчёты (аудит). Ничего
 * не пересчитывает и ничего не удаляет: строка — факт отправки.
 */
class MembershipRenewalReminder extends Model
{
    /** Append-only журнал: sent_at — факт отправки, updated_at не нужен. */
    public $timestamps = false;

    public const SURFACE_CLUB_PERIOD = 'club_period';

    public const SURFACE_ACCESS_WINDOW = 'access_window';

    protected $fillable = [
        'surface',
        'subject_id',
        'user_id',
        'stage',
        'period_ends_at',
        'channels',
        'sent_at',
    ];

    protected $casts = [
        'period_ends_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /** Уже напоминали эту стадию по этому объекту за ЭТОТ период? */
    public static function sentFor(
        string $surface,
        int $subjectId,
        string $stage,
        Carbon $periodEndsAt,
    ): bool {
        return self::query()
            ->where('surface', $surface)
            ->where('subject_id', $subjectId)
            ->where('stage', $stage)
            ->where('period_ends_at', $periodEndsAt)
            ->exists();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
