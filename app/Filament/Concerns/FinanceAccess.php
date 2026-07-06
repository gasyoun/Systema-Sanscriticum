<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Support\RoleGate;

/**
 * Применяется к Filament-ресурсам управленческого финансового контура
 * (расходы/opex, отчёты). Доступ — администратор ИЛИ бухгалтер (+ супер-админ),
 * по ruling MG в H116 (RoleGate::any(ADMIN, ACCOUNTANT)). Шире, чем AccountingOnly
 * (только бухгалтер): владелец-админ тоже ведёт финансы.
 */
trait FinanceAccess
{
    public static function canViewAny(): bool
    {
        return RoleGate::finance();
    }

    public static function canCreate(): bool
    {
        return RoleGate::finance();
    }

    public static function canEdit($record): bool
    {
        return RoleGate::finance();
    }

    public static function canDelete($record): bool
    {
        return RoleGate::finance();
    }

    public static function canDeleteAny(): bool
    {
        return RoleGate::finance();
    }
}
