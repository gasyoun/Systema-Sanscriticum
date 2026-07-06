<?php

namespace App\Filament\Resources\IntakeResource\Pages;

use App\Filament\Resources\IntakeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIntakes extends ListRecords
{
    protected static string $resource = IntakeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
