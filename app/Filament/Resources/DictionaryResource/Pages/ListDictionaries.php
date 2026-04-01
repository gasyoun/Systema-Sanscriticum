<?php

namespace App\Filament\Resources\DictionaryResource\Pages;

use App\Filament\Resources\DictionaryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDictionaries extends ListRecords
{
    protected static string $resource = DictionaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
