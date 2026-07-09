<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\DelegationKpiService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * «KPI делегирования» — панель оператора (H259, фаза D, венец плана noboring
 * /cases/education).
 *
 * Один экран, сводящий главные цифры всех четырёх фаз (окупаемость привлечения,
 * прибыль по начислению, отложенная выручка, дебиторка против порога, резервный
 * фонд) со светофорами. Цель — вывести собственника из операционки: оператор/
 * бухгалтер ведёт бизнес по этой панели, не заходя к владельцу. Каждая карточка
 * ведёт на подробную страницу фазы.
 *
 * Стоит ПЕРВОЙ в группе «Финансы» (navigationSort 78) — это то, что оператор
 * открывает утром. Красный бейдж = где-то красный флаг, надо разбираться.
 *
 * Считается из живых сервисов фаз через {@see DelegationKpiService}. Доступ —
 * админ ИЛИ бухгалтер (RoleGate::finance).
 */
class DelegationKpi extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-signal';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 78;

    protected static ?string $navigationLabel = 'KPI делегирования';

    protected static ?string $title = 'KPI делегирования — панель оператора';

    protected static ?string $slug = 'delegation-kpi';

    protected static string $view = 'filament.pages.delegation-kpi';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    /**
     * Красный бейдж, когда хоть одна фаза в тревоге.
     *
     * ВАЖНО: значок читается из кэша, а НЕ пересчитывает snapshot() на каждой
     * загрузке админки — иначе тяжёлый каскад агрегатов (LTV/CAC, ОПиУ, дебиторка,
     * фонды) выполняется на любом хите /admin у любого админа и роняет панель в 500
     * под нагрузкой. Ключ наполняется при открытии самой страницы / по расписанию.
     */
    public static function getNavigationBadge(): ?string
    {
        return \Illuminate\Support\Facades\Cache::get('nav_badge.delegation_kpi');
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
        $snap = app(DelegationKpiService::class)->snapshot();

        // Прогреваем кэш значка из уже посчитанного снимка (см. getNavigationBadge).
        \Illuminate\Support\Facades\Cache::put(
            'nav_badge.delegation_kpi',
            $snap['ok'] ? null : '!',
            now()->addMinutes(30),
        );

        return [
            'snap' => $snap,
        ];
    }
}
