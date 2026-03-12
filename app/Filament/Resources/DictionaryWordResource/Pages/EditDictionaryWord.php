<?php

namespace App\Filament\Resources\DictionaryWordResource\Pages;

use App\Filament\Resources\DictionaryWordResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDictionaryWord extends EditRecord
{
    protected static string $resource = DictionaryWordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
