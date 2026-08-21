<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseResource\Pages;

use App\Filament\Exports\CourseExporter;
use App\Filament\Resources\CourseResource;
use App\Support\CourseListHeading;
use Filament\Actions;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Resources\Pages\ListRecords;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    public function getTitle(): string
    {
        return CourseListHeading::forQuery(CourseResource::getEloquentQuery());
    }

    public function getHeading(): string
    {
        return $this->getTitle();
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),

            Actions\ExportAction::make()
                ->label('Экспорт в CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->exporter(CourseExporter::class)
                ->formats([
                    ExportFormat::Csv,
                ])
                ->fileName(fn () => 'courses-'.now()->format('Y-m-d_H-i-s')),
        ];
    }
}
