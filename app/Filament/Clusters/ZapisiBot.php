<?php

declare(strict_types=1);

namespace App\Filament\Clusters;

use App\Support\RoleGate;
use Filament\Clusters\Cluster;

/**
 * Track C (H164): admin-only cluster for @zapisi_ORSbot — a private
 * class-booking chat, not a publishable Sanskrit group (Legal gate applies).
 */
class ZapisiBot extends Cluster
{
    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationLabel = 'Записи (бот)';

    protected static ?string $navigationGroup = 'Telegram';

    protected static ?int $navigationSort = 66;

    protected static ?string $slug = 'zapisi-bot';

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }
}
