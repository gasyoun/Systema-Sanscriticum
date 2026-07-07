<?php

declare(strict_types=1);

namespace App\Filament\Pages\LeadCost;

use App\Services\Reports\ChannelConversionReport;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Widgets\Widget;

/**
 * A1, шаг 3: конверсия канал → оплата за месяц (users.utm_source → payments).
 * Простой read-only виджет без Filament\Tables — данных мало (число каналов),
 * фильтрации/пагинации не требуется.
 */
class ChannelConversionWidget extends Widget
{
    protected static string $view = 'filament.pages.lead-cost.channel-conversion';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return RoleGate::any(Roles::ADMIN, Roles::MANAGER);
    }

    /**
     * @return array<int, array{channel: string, users: int, matched_to_lead: int, paid_users: int, revenue: float, conversion: float}>
     */
    public function getRows(): array
    {
        return ChannelConversionReport::forMonth(now());
    }
}
