<?php

namespace App\Filament\Resources\DonationGratitudeResource\Pages;

use App\Filament\Resources\DonationGratitudeResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditDonationGratitude extends EditRecord
{
    protected static string $resource = DonationGratitudeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
