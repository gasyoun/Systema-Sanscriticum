<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Закрытый период ЗП преподавателя (teacher_id + period_month 'YYYY-MM').
 * Наличие записи = месяц закрыт; новые начисления в него не признаются, а
 * переносятся вперёд (TeacherSalaryService).
 */
class SalaryClosedPeriod extends Model
{
    protected $fillable = [
        'teacher_id',
        'period_month',
        'closed_at',
        'closed_by',
    ];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }
}
