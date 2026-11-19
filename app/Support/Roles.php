<?php

declare(strict_types=1);

namespace App\Support;

final class Roles
{
    public const SUPER_ADMIN = 'super_admin';

    public const ADMIN = 'admin';

    public const TEACHER = 'teacher';

    public const MANAGER = 'manager';

    public const ACCOUNTANT = 'accountant';

    /**
     * @return array<string,string> [code => human label]
     */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN => 'Супер-админ',
            self::ADMIN => 'Администратор',
            self::TEACHER => 'Преподаватель',
            self::MANAGER => 'Менеджер',
            self::ACCOUNTANT => 'Бухгалтер',
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

    /**
     * H6352-r5 (рулинг 7 грилла 10-10): персоналские роли, которым обязателен
     * второй фактор. Преподаватель не входит: денежных и пользовательских
     * экранов у него нет, а доступ в панель уже сужен canAccessPanel.
     *
     * @return array<string>
     */
    public static function twoFactorRequired(): array
    {
        return [self::SUPER_ADMIN, self::ADMIN, self::MANAGER, self::ACCOUNTANT];
    }
}
