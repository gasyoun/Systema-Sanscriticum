<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Crm\SalesForecastService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * CRM Wave 3 (H2485) — pipeline-weighted forecast + manager dashboard.
 *
 * Reads money. Never writes Payment / tariff / access. Flag
 * `features.crm_sales_forecast` default OFF. Manager sees only own
 * assigned_to pipeline and own created_by actuals
 * ({@see RoleGate::managerSalesReport()}).
 */
class SalesForecast extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 74;

    protected static ?string $navigationLabel = 'Прогноз продаж';

    protected static ?string $title = 'Прогноз продаж';

    protected static ?string $slug = 'sales-forecast';

    protected static string $view = 'filament.pages.sales-forecast';

    public static function canAccess(): bool
    {
        return (bool) config('features.crm_sales_forecast') && RoleGate::managerSalesReport();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $scoped = $user !== null && $user->role === Roles::MANAGER;
        $snap = app(SalesForecastService::class)->snapshot(
            onlyManagerId: $scoped ? $user->id : null,
        );

        return [
            'snap' => $snap,
            'scoped' => $scoped,
        ];
    }
}
