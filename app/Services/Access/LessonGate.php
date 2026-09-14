<?php

declare(strict_types=1);

namespace App\Services\Access;

use App\Http\Controllers\StudentController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\User;
use App\Services\Membership\ClubEntitlement;

/**
 * H3315 — единый гейт «может ли студент смотреть этот урок»: ТА ЖЕ цепочка,
 * что у плеера StudentController::showLesson и H3308 GatedAssetController:
 *
 *   грант на урок / клубное покрытие / видимость по группе курса — основания
 *   ПРОХОДА мимо группового фильтра; бесплатный/оплаченный (is_free /
 *   is_preview / isUnlockedBy) — отдельное ИЗ ТЕХ ЖЕ оснований условие
 *   (грант обходит и его).
 *
 * Один источник правды для выдачи закрытых ассетов и API-записи телеметрии
 * (heartbeat watch-time): раньше цепочка копировалась в каждом контроллере,
 * и API-путь мог записать активность по уроку, который студент смотреть не может.
 */
final class LessonGate
{
    /**
     * Дословная семантика плеера: «видимость» И «открыт/оплачен» должны
     * выполняться ОБЕ; персональный грант закрывает обе сразу.
     */
    public function canWatch(User $user, Lesson $lesson): bool
    {
        $course = $lesson->course;

        // Осиротевший урок (без курса) смотреть нельзя — консервативно.
        if (! ($course instanceof Course)) {
            return false;
        }

        $hasLessonGrant = LessonAccessGrant::userCanWatch($user, $lesson);
        $club = app(ClubEntitlement::class);
        $clubCovers = $club->coversCourse($user, $course);
        $clubLesson = $club->coversLesson($user, $course, $lesson);

        $visible = $hasLessonGrant || $clubCovers || $clubLesson || $lesson->isVisibleToGroupsOf($user);
        if (! $visible) {
            return false;
        }

        return (bool) $lesson->is_free
            || $hasLessonGrant
            || $clubLesson
            || $lesson->isUnlockedBy(StudentController::getUserUnlockedTariffs($user->id, $course->slug));
    }
}
