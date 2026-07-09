<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\ReceivablesGovernanceService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * «Дебиторка: план-факт» (H257, фаза C плана noboring /cases/education).
 *
 * Контур управления рассрочкой поверх витрины должников: текущая дебиторка
 * против порога «максимально допустимой», доля неликвидной (просроченной сверх
 * N дней), динамика неделя-к-неделе, разрез рассрочка/прочее и статус лимитов
 * рассрочки. Красный алерт при превышении — это и есть замена ручного контроля
 * владельца (та же логика питает демон receivables:check).
 *
 * Считается из живой БД через {@see ReceivablesGovernanceService}; порог и лимиты
 * берутся из config/receivables.php, а не зашиты в вёрстку.
 *
 * Доступ — админ ИЛИ бухгалтер (RoleGate::finance): это финансовый контур,
 * который делегированный финдир читает сам, без владельца.
 */
class ReceivablesGovernance extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-scale';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 73;

    protected static ?string $navigationLabel = 'Дебиторка: план-факт';

    protected static ?string $title = 'Дебиторка и рассрочка — план-факт';

    protected static ?string $slug = 'receivables-governance';

    protected static string $view = 'filament.pages.receivables-governance';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    /**
     * Красный бейдж в меню, когда контур в тревоге — оператор видит проблему,
     * не заходя на страницу.
     */
    public static function getNavigationBadge(): ?string
    {
        // Из кэша, без синхронного пересчёта на каждой загрузке админки — см.
        // DelegationKpi::getNavigationBadge (тяжёлый snapshot ронял панель в 500).
        return \Illuminate\Support\Facades\Cache::get('nav_badge.receivables');
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
        $snap = app(ReceivablesGovernanceService::class)->snapshot();

        \Illuminate\Support\Facades\Cache::put(
            'nav_badge.receivables',
            $snap['ok'] ? null : '!',
            now()->addMinutes(30),
        );

        return [
            'snap' => $snap,
        ];
    }
}
