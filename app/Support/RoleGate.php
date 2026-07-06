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
}
