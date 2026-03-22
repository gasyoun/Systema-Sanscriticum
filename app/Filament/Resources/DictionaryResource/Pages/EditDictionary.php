<?php

namespace App\Filament\Resources\DictionaryResource\Pages;

use App\Filament\Resources\DictionaryResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDictionary extends EditRecord
{
    protected static string $resource = DictionaryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
