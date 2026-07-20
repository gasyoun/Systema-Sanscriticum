<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\ProfitFundsService;
use App\Support\RoleGate;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * «Фонды прибыли» (H259, фаза D плана noboring /cases/education).
 *
 * Распределение чистой прибыли по начислению на фонды (дивиденды/резерв/команда/
 * компания) с настраиваемыми долями, накопление за окно и сверка резерва-подушки
 * с реальной кассой. Прямое воплощение системы фондов из кейса «Аура».
 *
 * Считается из живого ОПиУ через {@see ProfitFundsService}; доли и окно — из
 * config/profit_funds.php, а не зашиты в вёрстку.
 *
 * Доступ — админ ИЛИ бухгалтер (RoleGate::finance): финансовый контур, который
 * делегированный финдир читает сам.
 */
class ProfitFunds extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 82;

    protected static ?string $navigationLabel = 'Фонды прибыли';

    protected static ?string $title = 'Фонды прибыли — распределение и резерв';

    protected static ?string $slug = 'profit-funds';

    protected static string $view = 'filament.pages.profit-funds';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    /**
     * Красный бейдж в меню, когда резерв не обеспечен кассой — оператор видит
     * проблему, не заходя на страницу.
     */
    public static function getNavigationBadge(): ?string
    {
        // Из кэша, без синхронного пересчёта на каждой загрузке админки — см.
        // DelegationKpi::getNavigationBadge (тяжёлый snapshot ронял панель в 500).
        return Cache::get('nav_badge.profit_funds');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $snap = app(ProfitFundsService::class)->snapshot();

        Cache::put(
            'nav_badge.profit_funds',
            ($snap['reserve_level'] ?? null) === 'danger' ? '!' : null,
            now()->addMinutes(30),
        );

        return [
            'snap' => $snap,
        ];
    }
}
