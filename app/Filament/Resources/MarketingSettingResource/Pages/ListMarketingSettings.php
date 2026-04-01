<?php

namespace App\Filament\Resources\MarketingSettingResource\Pages;

use App\Filament\Resources\MarketingSettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListMarketingSettings extends ListRecords
{
    protected static string $resource = MarketingSettingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
