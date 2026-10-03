<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H5823 — одна ушедшая стадия напоминания о продлении членства.
 *
 * Читатели: демон напоминаний (дедуп) и кабинет/отчёты (аудит). Ничего
 * не пересчитывает и ничего не удаляет: строка — факт отправки, единственное
 * намеренно денормализованное поле — period_ends_at (какая дата была
 * «оплачено до» на момент напоминания).
 */
class MembershipRenewalReminder extends Model
{
    /** Append-only журнал: sent_at — факт отправки, updated_at не нужен. */
    public $timestamps = false;

    protected $fillable = [
        'club_membership_id',
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

    public function membership(): BelongsTo
    {
        return $this->belongsTo(ClubMembership::class, 'club_membership_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Уже напоминали эту стадию по этому периоду? */
    public static function sentFor(int $membershipId, string $stage): bool
    {
        return self::query()
            ->where('club_membership_id', $membershipId)
            ->where('stage', $stage)
            ->exists();
    }
}
