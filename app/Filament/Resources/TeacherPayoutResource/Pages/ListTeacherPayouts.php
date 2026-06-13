<?php

namespace App\Filament\Resources\TeacherPayoutResource\Pages;

use App\Filament\Resources\TeacherPayoutResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListTeacherPayouts extends ListRecords
{
    protected static string $resource = TeacherPayoutResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
