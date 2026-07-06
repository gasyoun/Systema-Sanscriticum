<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Intake extends Model
{
    use HasFactory;

    /** Жизненный цикл набора (единый источник для формы/бейджа/фильтра). */
    public const STATUSES = [
        'planning' => 'Планируется',
        'forming' => 'Формируется',
        'open' => 'Идёт набор',
        'closed' => 'Закрыт',
    ];

    protected $fillable = [
        'label',
        'season',
        'target_year',
        'target_month',
        'planned_group_numbers',
        'status',
        'opens_at',
        'notes',
    ];

    protected $casts = [
        // Известные номера будущих групп до их создания.
        'planned_group_numbers' => 'array',
        'target_year' => 'integer',
        'target_month' => 'integer',
        'opens_at' => 'date',
    ];

    /** Заявки в этот набор. */
    public function waitlistEntries(): HasMany
    {
        return $this->hasMany(WaitlistEntry::class);
    }

    /** Реальные группы, порождённые набором после формирования. */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }

    /** Человекочитаемая подпись статуса. */
    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
