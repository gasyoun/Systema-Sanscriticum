<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H3084 — предложение «платёж-«Расход» на самом деле выплата преподавателю».
 *
 * Строка НИЧЕГО не значит для денег, пока бухгалтер не перевёл её в
 * `confirmed`. И даже тогда меняется только `paid_out` сверки: строки в
 * `teacher_payouts` и `payments` не создаются и не правятся — перенос в
 * выплатной реестр остаётся отдельным действием человека (см. §14
 * IMPLEMENTATION и приёмку H3084).
 */
class TeacherPayoutAttributionSuggestion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'payment_id',
        'teacher_id',
        'course_id',
        'course_family',
        'amount',
        'paid_on',
        'confidence',
        'reason',
        'status',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
        'confidence' => 'float',
        'resolved_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_CONFIRMED);
    }
}
