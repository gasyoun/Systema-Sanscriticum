<?php

namespace App\Filament\Resources\DonationGratitudeResource\Pages;

use App\Filament\Resources\DonationGratitudeResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListDonationGratitudes extends ListRecords
{
    protected static string $resource = DonationGratitudeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Добавить мецената'),
        ];
    }
}
