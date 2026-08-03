<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Reports\OrderPaymentConversionService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * «Продажи по менеджеру» (GC-C2, H2058 residual /
 * docs/GETCOURSE_PARITY_PRODUCTION_SPEC_2026.md §4). F5 RULED 02-08-2026 (MG):
 *
 * (a) join — {@see OrderPaymentConversionService::managerScoreboard()} groups
 * orders by `payments.created_by_user_id` (who created the payment row), NOT
 * `leads.assigned_to` — that column is near-empty by construction on
 * conversion-eligible tariffs (populated only on deposit/trial/marathon, and
 * deposit/trial are excluded from the order denominator).
 *
 * (b) visibility — super_admin/admin/accountant see ALL rows (including the
 * «Без менеджера» unassigned bucket); a plain manager sees ONLY their own
 * rows (`created_by_user_id = auth id`). This is a SEPARATE surface from
 * {@see OrderPaymentConversion} (which stays `RoleGate::finance()`,
 * ADMIN+ACCOUNTANT only, MANAGER excluded by test) — that page is not widened.
 *
 * Deploy-inert while `features.manager_sales_report` is OFF (default):
 * canAccess() returns false even for an admin.
 */
class ManagerSalesReport extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 73;

    protected static ?string $navigationLabel = 'Продажи по менеджеру';

    protected static ?string $title = 'Продажи по менеджеру';

    protected static ?string $slug = 'manager-sales-report';

    protected static string $view = 'filament.pages.manager-sales-report';

    public static function canAccess(): bool
    {
        return (bool) config('features.manager_sales_report') && RoleGate::managerSalesReport();
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

        // super_admin проходит через RoleGate::any() независимо от role — но
        // здесь важна именно РОЛЬ (manager сужается, super_admin/admin/
        // accountant — нет), поэтому сверяем role напрямую, а не через гейт.
        $scoped = $user !== null && $user->role === Roles::MANAGER;

        $snap = app(OrderPaymentConversionService::class)->managerScoreboard($scoped ? $user->id : null);

        return [
            'snap' => $snap,
            'scoped' => $scoped,
        ];
    }
}
