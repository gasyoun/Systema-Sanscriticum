<?php

namespace App\Filament\Resources\ZapisiClassScheduleResource\Pages;

use App\Filament\Resources\ZapisiClassScheduleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListZapisiClassSchedules extends ListRecords
{
    protected static string $resource = ZapisiClassScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
