<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\Models\TeacherCompensationAssignment;
use Carbon\CarbonInterface;

/**
 * H5444 (D17): единственный путь от «преподаватель + курс + дата» к ставке.
 *
 * Читает ТОЛЬКО `teacher_compensation_assignments`. Роли, `course_teacher`,
 * `group_reviewer`, доступ к курсу и `courses.salary_value` здесь не
 * существуют — по построению, а не по договорённости.
 *
 * Разрешение: назначение на курс перекрывает школьное; при равенстве
 * выигрывает более позднее по `effective_from` (последняя договорённость).
 */
final class CompensationResolver
{
    /**
     * @throws UnresolvedCompensation когда действующего назначения нет
     */
    public function resolve(int $teacherId, ?int $courseId, CarbonInterface $onDate): TeacherCompensationAssignment
    {
        return $this->find($teacherId, $courseId, $onDate)
            ?? throw new UnresolvedCompensation($teacherId, $courseId, $onDate->toDateString());
    }

    /** Мягкий вариант для отчётов сверки: `null` вместо исключения. */
    public function find(int $teacherId, ?int $courseId, CarbonInterface $onDate): ?TeacherCompensationAssignment
    {
        $day = $onDate->toDateString();

        $candidates = TeacherCompensationAssignment::query()
            ->with('term')
            ->where('teacher_id', $teacherId)
            ->whereNull('revoked_at')
            ->whereDate('effective_from', '<=', $day)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $day))
            ->where(function ($q) use ($courseId) {
                $q->where('scope_kind', TeacherCompensationAssignment::SCOPE_SCHOOL);

                if ($courseId !== null) {
                    $q->orWhere(fn ($c) => $c
                        ->where('scope_kind', TeacherCompensationAssignment::SCOPE_COURSE)
                        ->where('course_id', $courseId));
                }
            })
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();

        return $candidates->firstWhere('scope_kind', TeacherCompensationAssignment::SCOPE_COURSE)
            ?? $candidates->first();
    }
}
