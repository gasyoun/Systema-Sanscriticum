<?php

namespace App\Filament\Resources\TeacherVacationResource\Pages;

use App\Filament\Resources\TeacherVacationResource;
use Filament\Resources\Pages\ListRecords;

class ListTeacherVacations extends ListRecords
{
    protected static string $resource = TeacherVacationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
