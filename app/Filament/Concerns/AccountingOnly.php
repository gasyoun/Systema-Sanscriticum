<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\RoleGate;

/**
 * Применяется к Filament-ресурсам с финансовыми данными по выплатам.
 * Доступ только у бухгалтера и супер-админа; обычный admin НЕ проходит.
 * Скрывает ресурс из навигации и блокирует все CRUD-действия для остальных ролей.
 */
trait AccountingOnly
{
    public static function canViewAny(): bool
    {
        return RoleGate::accounting();
    }

    public static function canCreate(): bool
    {
        return RoleGate::accounting();
    }

    public static function canEdit($record): bool
    {
        return RoleGate::accounting();
    }

    public static function canDelete($record): bool
    {
        return RoleGate::accounting();
    }

    public static function canDeleteAny(): bool
    {
        return RoleGate::accounting();
    }
}
