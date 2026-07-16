<?php

declare(strict_types=1);

namespace App\Filament\Pages\Rq4Study;

use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Pages\Page;

/**
 * H1005 -- RQ4 study enrollment dashboard, admin-only. Lets a human check
 * enrollment/arm-split/phase-completion numbers via the web admin panel --
 * no SSH or artisan command needed (MG doesn't hold SSH creds to the prod
 * server; only the deploy contractor does).
 */
class Rq4StudyDashboard extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-beaker';

    protected static ?string $navigationGroup = 'Исследования';

    protected static ?int $navigationSort = 90;

    protected static ?string $navigationLabel = 'RQ4: ряды/типы корней';

    protected static ?string $title = 'RQ4 — исследование ряды/типы корней';

    protected static ?string $slug = 'rq4-study-dashboard';

    protected static string $view = 'filament.pages.rq4-study-dashboard';

    public static function canAccess(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::SUPER_ADMIN);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::SUPER_ADMIN);
    }
}
