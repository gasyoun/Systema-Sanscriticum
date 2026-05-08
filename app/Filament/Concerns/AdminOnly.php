<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\RoleGate;

/**
 * Применяется к Filament-ресурсам, доступным только админу и супер-админу.
 * Скрывает ресурс из навигации и блокирует все CRUD-действия для остальных ролей.
 */
trait AdminOnly
{
    public static function canViewAny(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canCreate(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canEdit($record): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canDelete($record): bool
    {
        return RoleGate::adminOnly();
    }

    public static function canDeleteAny(): bool
    {
        return RoleGate::adminOnly();
    }
}
