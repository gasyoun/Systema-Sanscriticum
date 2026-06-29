<?php

namespace App\Filament\Resources\SupportResponderMappingResource\Pages;

use App\Filament\Resources\SupportResponderMappingResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSupportResponderMapping extends EditRecord
{
    protected static string $resource = SupportResponderMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
