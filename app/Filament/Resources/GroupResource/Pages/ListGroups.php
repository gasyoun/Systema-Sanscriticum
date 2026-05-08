<?php

declare(strict_types=1);

namespace App\Filament\Resources\GroupResource\Pages;

use App\Filament\Exports\GroupExporter;
use App\Filament\Resources\GroupResource;
use Filament\Actions;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Resources\Pages\ListRecords;

class ListGroups extends ListRecords
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\ExportAction::make()
                ->label('Экспорт в CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->exporter(GroupExporter::class)
                ->formats([ExportFormat::Csv])
                ->fileName(fn () => 'groups-' . now()->format('Y-m-d_H-i-s')),
        ];
    }
}