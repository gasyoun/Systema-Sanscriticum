<?php

namespace App\Filament\Resources\SupportResponderMappingResource\Pages;

use App\Filament\Resources\SupportResponderMappingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSupportResponderMappings extends ListRecords
{
    protected static string $resource = SupportResponderMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
