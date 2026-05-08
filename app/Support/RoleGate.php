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
        if (!$user instanceof User) {
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

    public static function isSuperAdmin(): bool
    {
        $user = auth()->user();
        return $user instanceof User && $user->isSuperAdmin();
    }
}
