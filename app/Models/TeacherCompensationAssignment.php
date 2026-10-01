<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * H5444 (D17): датированное назначение компенсации — ЕДИНСТВЕННОЕ, что создаёт
 * денежное обязательство перед преподавателем.
 *
 * Ни роль `teacher`, ни `course_teacher`, ни доступ к курсу сюда не ведут:
 * смена роли не создаёт, не меняет и не прекращает обязательство без отдельной
 * записи назначения. Правка не допускается — назначение отзывается
 * (`revoked_at`), новое выдаётся отдельной строкой.
 */
class TeacherCompensationAssignment extends Model
{
    public const SCOPE_SCHOOL = 'school';

    public const SCOPE_COURSE = 'course';

    protected $fillable = [
        'assignment_key', 'teacher_id', 'term_id', 'scope_kind', 'course_id',
        'effective_from', 'effective_to', 'confirmed_by', 'confirmed_at', 'reason',
    ];

    protected $casts = [
        'effective_from' => 'date',
        'effective_to' => 'date',
        'confirmed_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    public function term(): BelongsTo
    {
        return $this->belongsTo(TeacherCompensationTerm::class, 'term_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function coversDate(\DateTimeInterface $date): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        $day = $date->format('Y-m-d');

        return $this->effective_from->format('Y-m-d') <= $day
            && ($this->effective_to === null || $this->effective_to->format('Y-m-d') >= $day);
    }
}
