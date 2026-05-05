<?php

declare(strict_types=1);

namespace App\Support;

final class Roles
{
    public const SUPER_ADMIN = 'super_admin';
    public const ADMIN       = 'admin';
    public const TEACHER     = 'teacher';
    public const MANAGER     = 'manager';

    /**
     * @return array<string,string> [code => human label]
     */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN => 'Супер-админ',
            self::ADMIN       => 'Администратор',
            self::TEACHER     => 'Преподаватель',
            self::MANAGER     => 'Менеджер',
        ];
    }

    /**
     * Роли, которые «админоподобны» в смысле legacy-флага is_admin.
     *
     * @return array<string>
     */
    public static function adminLike(): array
    {
        return [self::SUPER_ADMIN, self::ADMIN];
    }
}
