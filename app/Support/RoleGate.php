<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;

final class RoleGate
{
    /**
     * Хотя бы одна из перечисленных ролей у текущего пользователя.
     * super_admin всегда проходит мимо проверки.
     */
    public static function any(string ...$roles): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        return in_array($user->role, $roles, true);
    }

    public static function adminOnly(): bool
    {
        return self::any(Roles::ADMIN);
    }

    /**
     * Standing rule (H3219): admin-like staff see every teacher surface
     * (playlist preview, draft drills, load, homework). Overlay, not a role
     * change. To see a named teacher's own rows, use impersonation MODE_TEACHER.
     * Does not open school-wide salary/payout tables — those stay accounting()
     * except {@see seesOwnSalary()} for the logged-in teacher's own card.
     */
    public static function seesTeacherSurfaces(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user instanceof User && $user->isAdminLike();
    }

    /**
     * Own salary calculation: accountant/super_admin (all rows) or a teacher
     * with teacher_id (own card only). Ordinary admin without impersonation
     * does not pass — same as accounting().
     */
    public static function seesOwnSalary(?User $user = null): bool
    {
        if (self::accounting()) {
            return true;
        }

        $user ??= auth()->user();

        return $user instanceof User
            && $user->isTeacher()
            && $user->teacher_id !== null;
    }

    /**
     * Teacher card to scope salary/load rows. Null means «all teachers»
     * (accounting). Non-accounting teachers get their teacher_id.
     */
    public static function ownTeacherId(): ?int
    {
        if (self::accounting()) {
            return null;
        }

        $user = auth()->user();
        if ($user instanceof User && $user->isTeacher() && $user->teacher_id !== null) {
            return (int) $user->teacher_id;
        }

        return null;
    }

    /**
     * Выдача одноразовой magic-ссылки в кабинет студента (H849 /login-link).
     * Куратор (manager) и admin — оба; student/teacher/accountant — нет.
     * super_admin проходит через any().
     */
    public static function canIssueStudentLoginLink(): bool
    {
        return self::any(Roles::ADMIN, Roles::MANAGER);
    }

    /**
     * Доступ к выплатам/зарплатам: только бухгалтер и супер-админ.
     * Обычный admin сюда НЕ проходит (в отличие от adminOnly()).
     */
    public static function accounting(): bool
    {
        return self::any(Roles::ACCOUNTANT);
    }

    /**
     * Доступ к управленческим финотчётам (ОПиУ/ДДС/штурвал): администратор ИЛИ
     * бухгалтер (+ супер-админ всегда). Ruling MG в H116: RoleGate::any(ADMIN,
     * ACCOUNTANT) — шире, чем accounting(): владелец-админ тоже видит P&L.
     */
    public static function finance(): bool
    {
        return self::any(Roles::ADMIN, Roles::ACCOUNTANT);
    }

    public static function isSuperAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * Учебная аналитика — активация и завершаемость (H3764, рулинг MG
     * 01-09-2026): admin, accountant И manager (куратор); super_admin проходит
     * через any(). Изначально страница стояла на accounting(), но это была
     * ошибка адресата: активация и доходимость — не деньги и не зарплата, а
     * рабочий инструмент того, кто ведёт учеников. Куратор без этих цифр не
     * видит, кто не дошёл до первого урока.
     *
     * НЕ managerSalesReport(): тот гейт про выручку и сужает строки до своих
     * сделок. Здесь сужать нечего — цифры агрегатные, персональных данных и
     * сумм на странице нет, поэтому все четыре роли видят одно и то же.
     *
     * teacher и student не проходят: преподавателю нужна доходимость своего
     * курса, а не школы, и это отдельная задача с другим знаменателем.
     */
    public static function learningAnalytics(): bool
    {
        return self::any(Roles::ADMIN, Roles::ACCOUNTANT, Roles::MANAGER);
    }

    /**
     * Доступ к «Продажи по менеджеру» (GC-C2, F5(b) RULED 02-08-2026): admin,
     * accountant И manager — шире finance(), потому что менеджер должен видеть
     * СВОЙ срез (страница сама сужает строки до created_by_user_id=auth id вне
     * super_admin/admin/accountant — см. ManagerSalesReport::getViewData()).
     * teacher/student не проходят.
     */
    public static function managerSalesReport(): bool
    {
        return self::any(Roles::ADMIN, Roles::ACCOUNTANT, Roles::MANAGER);
    }
}
