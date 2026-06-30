<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Group;
use App\Models\User;

/**
 * Единый источник правды о «мягком выходе» студента из учебной группы.
 *
 * Членство в группе (`group_user`) играет двойную роль: (1) ДОСТУП/листинг курса
 * в ЛК и (2) «активный состав» (ростеры, напоминания, чаты). Удалять строку
 * нельзя — потеряется доступ к оплаченному. Поэтому «выход» = пометка
 * `left_at` на пивоте; строка остаётся, а из активного состава участник
 * исчезает (см. User::activeGroups / Group::activeUsers).
 *
 * Приоритет: ручная пометка (`manual`) важнее авто-синка по статусу (`status`) —
 * авто-операции не трогают то, что зафиксировал куратор руками.
 */
class GroupMembershipManager
{
    public const REASON_STATUS = 'status';

    public const REASON_MANUAL = 'manual';

    /**
     * Терминальные статусы обучения на курсе (course_user.status), при которых
     * студент считается выбывшим из активного состава групп курса. Совпадает с
     * набором, по которому CoursesRelationManager показывает «блок выхода».
     */
    public const TERMINAL_STATUSES = ['Выпускник', 'Покинул', 'Исключен'];

    /**
     * @param  array<int>  $groupIds
     */
    public function softLeave(User $user, array $groupIds, string $reason): void
    {
        $this->apply($user, $groupIds, leave: true, reason: $reason);
    }

    /**
     * @param  array<int>  $groupIds
     */
    public function restore(User $user, array $groupIds, string $reason): void
    {
        $this->apply($user, $groupIds, leave: false, reason: $reason);
    }

    /**
     * Привести членство студента в группах конкретного курса в соответствие с его
     * статусами обучения. Вызывается после правки статуса в «Обучается на курсах».
     */
    public function syncForCourse(User $user, Course $course): void
    {
        $course->loadMissing('groups');

        foreach ($course->groups as $group) {
            if ($this->shouldBeActive($user, $group)) {
                $this->restore($user, [$group->id], self::REASON_STATUS);
            } else {
                $this->softLeave($user, [$group->id], self::REASON_STATUS);
            }
        }
    }

    /**
     * Реконсиляция всех групп студента по его курсовым статусам (бэкфилл/страховка).
     */
    public function syncAllForUser(User $user): void
    {
        $user->loadMissing('groups');

        foreach ($user->groups as $group) {
            if ($this->shouldBeActive($user, $group)) {
                $this->restore($user, [$group->id], self::REASON_STATUS);
            } else {
                $this->softLeave($user, [$group->id], self::REASON_STATUS);
            }
        }
    }

    /**
     * Членство активно ⇔ среди курсов, к которым привязана группа, у студента есть
     * хотя бы один НЕ терминальный статус (или вообще нет данных о статусе —
     * тогда консервативно не архивируем). Группа может обслуживать несколько
     * курсов: пока активен хоть один — состав активен.
     */
    private function shouldBeActive(User $user, Group $group): bool
    {
        $courseIds = $group->courses()->pluck('courses.id')->all();
        if (empty($courseIds)) {
            return true; // группа ни к чему не привязана — не трогаем
        }

        $statuses = $user->courses()
            ->whereIn('courses.id', $courseIds)
            ->get()
            ->pluck('pivot.status');

        if ($statuses->isEmpty()) {
            return true; // нет записей course_user — не архивируем по отсутствию данных
        }

        return $statuses->contains(
            fn ($status) => ! in_array($status, self::TERMINAL_STATUSES, true)
        );
    }

    /**
     * @param  array<int>  $groupIds
     */
    private function apply(User $user, array $groupIds, bool $leave, string $reason): void
    {
        foreach ($groupIds as $groupId) {
            $pivot = $user->groups()->where('groups.id', $groupId)->first()?->pivot;
            if (! $pivot) {
                continue; // студент не состоит в группе
            }

            // Авто-синк (status) не перетирает ручную фиксацию куратора.
            if ($reason === self::REASON_STATUS && $pivot->left_reason === self::REASON_MANUAL) {
                continue;
            }

            if ($leave) {
                $user->groups()->updateExistingPivot($groupId, [
                    'left_at' => $pivot->left_at ?? now(),
                    'left_reason' => $reason,
                ]);
            } else {
                $user->groups()->updateExistingPivot($groupId, [
                    'left_at' => null,
                    'left_reason' => null,
                ]);
            }
        }
    }
}
