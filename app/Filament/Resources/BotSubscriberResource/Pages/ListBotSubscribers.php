<?php

declare(strict_types=1);

namespace App\Filament\Resources\BotSubscriberResource\Pages;

use App\Filament\Resources\BotSubscriberResource;
use App\Filament\Resources\BotSubscriberResource\Widgets\BotFunnelStatsWidget;
use App\Filament\Resources\BotSubscriberResource\Widgets\OpenLessonSendsWidget;
use Filament\Resources\Pages\ListRecords;

class ListBotSubscribers extends ListRecords
{
    protected static string $resource = BotSubscriberResource::class;

    protected ?string $subheading = 'Ступени воронки, рассылки карточек открытых занятий и все, кто нажимал /start.';

    protected function getHeaderWidgets(): array
    {
        return [
            BotFunnelStatsWidget::class,
            OpenLessonSendsWidget::class,
        ];
    }
}
