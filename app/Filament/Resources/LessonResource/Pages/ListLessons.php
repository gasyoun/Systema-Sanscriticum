<?php

namespace App\Filament\Resources\LessonResource\Pages;

use App\Filament\Resources\LessonResource;
use App\Filament\Imports\LessonImporter; // <--- Добавили импорт класса
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLessons extends ListRecords
{
    protected static string $resource = LessonResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // НОВАЯ КНОПКА ИМПОРТА
            Actions\ImportAction::make()
                ->importer(LessonImporter::class)
                ->label('Импорт из Excel (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('info'),
                
            // Старая кнопка "Создать" (оставляем её)
            Actions\CreateAction::make(),
        ];
    }
}