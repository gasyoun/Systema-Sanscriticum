<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\Analytics\ActivationCompletionMetricsService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * «Активация и завершаемость» — O2 + C4 дорожной карты (H3764).
 *
 * Читает только: воронка активации по месячным когортам (оплатил → вошёл →
 * открыл урок → сдал домашнюю + медиана времени до первого урока) и
 * завершаемость по курсу и потоку. Ничего не пишет, денег не трогает.
 *
 * Флаг `features.activation_completion_metrics` ВЫКЛ по умолчанию (домашний
 * образец — {@see SalesForecast}): пока OFF, пункта меню нет и страница
 * недоступна никому. Гейт — {@see RoleGate::learningAnalytics()}: admin,
 * accountant и manager (куратор), плюс super_admin через any(). Изначально
 * стоял accounting() (рулинг MG 30-08-2026), расширен рулингом MG 01-09-2026:
 * доходимость учеников — рабочий инструмент куратора, а не бухгалтерия.
 *
 * Каждый процент на странице подписан своим знаменателем: цифра без
 * знаменателя не проверяема (урок H2378). Пороги — config/activation_metrics.php.
 */
class ActivationCompletionMetrics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-academic-cap';

    protected static ?string $navigationGroup = 'Продажи';

    protected static ?int $navigationSort = 76;

    protected static ?string $navigationLabel = 'Активация и завершаемость';

    protected static ?string $title = 'Активация и завершаемость (O2 · C4)';

    protected static ?string $slug = 'activation-completion-metrics';

    protected static string $view = 'filament.pages.activation-completion-metrics';

    public static function canAccess(): bool
    {
        return (bool) config('features.activation_completion_metrics') && RoleGate::learningAnalytics();
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
        return [
            'snap' => app(ActivationCompletionMetricsService::class)->snapshot(),
        ];
    }
}
