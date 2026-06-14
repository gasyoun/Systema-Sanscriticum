<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeacherPayout extends Model
{
    protected $fillable = [
        'teacher_id',
        'amount',
        'comment',
        'paid_at',
        'period_month',
        'course_id',
        'salary_type',
        'salary_value',
        'breakdown',
        'payment_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'salary_value' => 'decimal:2',
        'paid_at' => 'date',
        'breakdown' => 'array',
    ];

    protected static function booted(): void
    {
        // Удаление выплаты снимает её транзакцию-зеркало в «Финансах».
        // withoutEvents — у salary_payout нет побочных хуков, чистим тихо.
        static::deleting(function (self $payout): void {
            if ($payout->payment_id) {
                Payment::withoutEvents(fn () => Payment::whereKey($payout->payment_id)->delete());
            }
        });
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
