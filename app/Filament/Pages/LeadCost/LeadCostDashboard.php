<?php

declare(strict_types=1);

namespace App\Filament\Pages\LeadCost;

use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * Единый экран маркетинга: KPI + график динамики + таблицы расходов
 * (Директ помесячно и рекламные посты). Доступ — админ и менеджер.
 *
 * Виджеты лежат рядом (не в app/Filament/Widgets), поэтому НЕ попадают
 * на главный дашборд — рендерятся только здесь через blade.
 */
class LeadCostDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 71;

    protected static ?string $navigationLabel = 'Стоимость лида';

    protected static ?string $title = 'Стоимость лида';

    protected static ?string $slug = 'lead-cost';

    protected static string $view = 'filament.pages.lead-cost-dashboard';

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }
}
