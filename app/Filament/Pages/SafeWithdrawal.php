<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\SafeWithdrawalService;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * «Сколько можно взять себе» — практика «Нескучных финансов» (safe withdrawal).
 * Read-only: балансы − обязательства горизонта − налоговый резерв − операционный
 * резерв. Никогда не пишет teacher_payouts / payments. Итог двумя строками:
 * взносы за сотрудницу общими 30% vs МСП 15% свыше МРОТ.
 */
class SafeWithdrawal extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 81;

    protected static ?string $navigationLabel = 'Сколько можно взять себе';

    protected static ?string $title = 'Сколько можно взять себе (безопасная сумма вывода)';

    protected static ?string $slug = 'safe-withdrawal';

    protected static string $view = 'filament.pages.safe-withdrawal';

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return [
            'sw' => app(SafeWithdrawalService::class)->snapshot(),
            'cockpitUrl' => FinanceCockpit::getUrl(),
            'calendarUrl' => TeacherWeeklyPayoutCalendar::getUrl(),
            'profitFundsUrl' => ProfitFunds::getUrl(),
        ];
    }
}
