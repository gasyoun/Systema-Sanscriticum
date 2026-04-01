<?php

namespace App\Filament\Resources\DictionaryWordResource\Pages;

use App\Filament\Resources\DictionaryWordResource;
use App\Filament\Imports\DictionaryWordImporter;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDictionaryWords extends ListRecords
{
    protected static string $resource = DictionaryWordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Кнопка импорта
            Actions\ImportAction::make()
                ->importer(DictionaryWordImporter::class)
                ->label('Импорт из CSV')
                ->color('success')
                ->icon('heroicon-o-arrow-down-tray'),
                
            // Стандартная кнопка создания одного слова
            Actions\CreateAction::make()
                ->label('Добавить слово'),
        ];
    }
}