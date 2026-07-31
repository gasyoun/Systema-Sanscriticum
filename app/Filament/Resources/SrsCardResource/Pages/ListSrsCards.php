<?php

declare(strict_types=1);

namespace App\Filament\Resources\SrsCardResource\Pages;

use App\Filament\Exports\SrsCardExporter;
use App\Filament\Imports\SrsCardImporter;
use App\Filament\Resources\SrsCardResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSrsCards extends ListRecords
{
    protected static string $resource = SrsCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\ImportAction::make()
                ->importer(SrsCardImporter::class)
                ->label('Импорт CSV')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray'),

            // Optional re-upload to Memrise: Systema remains the source of truth.
            Actions\ExportAction::make()
                ->exporter(SrsCardExporter::class)
                ->label('Экспорт CSV')
                ->color('gray')
                ->icon('heroicon-o-arrow-up-tray'),

            Actions\CreateAction::make()->label('Добавить карточку'),
        ];
    }
}
