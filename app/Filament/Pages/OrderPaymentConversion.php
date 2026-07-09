<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Reports\OrderPaymentConversionService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * «Конверсия заказ→оплата» (H262, фаза F плана noboring /cases/education).
 *
 * Операционный отчёт по воронке из кейса «Нашли и обезвредили слабое место в
 * продажах»: какая доля оформленных заказов доходит до денег, где именно течёт
 * (по курсу/каналу), динамика период-к-периоду, и рабочий список НЕДОЖАТЫХ
 * заказов (кого дожать) — чтобы отдел продаж/оператор видел утечку числом без
 * участия владельца.
 *
 * Считается из живой БД через {@see OrderPaymentConversionService}; пороги/окна
 * берутся из config/conversion.php, а не зашиты в вёрстку.
 *
 * Доступ — админ ИЛИ бухгалтер (RoleGate::finance): управленческий контур
 * продаж, который делегированный финдир/оператор читает сам.
 */
class OrderPaymentConversion extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-funnel';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 72;

    protected static ?string $navigationLabel = 'Конверсия заказ→оплата';

    protected static ?string $title = 'Конверсия «оформленный заказ → оплата»';

    protected static ?string $slug = 'order-payment-conversion';

    protected static string $view = 'filament.pages.order-payment-conversion';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    /**
     * Жёлтый бейдж в меню = число недожатых заказов: оператор видит рабочий
     * список «кого дожать», не заходя на страницу. null (без бейджа), когда всё
     * дожато.
     */
    public static function getNavigationBadge(): ?string
    {
        // Из кэша, без синхронного пересчёта на каждой загрузке админки — см.
        // DelegationKpi::getNavigationBadge (тяжёлый агрегат ронял панель в 500).
        return \Illuminate\Support\Facades\Cache::get('nav_badge.order_conversion');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $snap = app(OrderPaymentConversionService::class)->snapshot();

        $unclosedCount = count($snap['unclosed'] ?? []);
        \Illuminate\Support\Facades\Cache::put(
            'nav_badge.order_conversion',
            $unclosedCount > 0 ? (string) $unclosedCount : null,
            now()->addMinutes(30),
        );

        return [
            'snap' => $snap,
        ];
    }
}
