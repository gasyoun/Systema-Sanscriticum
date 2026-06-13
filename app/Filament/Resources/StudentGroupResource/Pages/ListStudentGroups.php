<?php

namespace App\Filament\Resources\StudentGroupResource\Pages;

use App\Filament\Resources\StudentGroupResource;
use Filament\Resources\Pages\ListRecords;

class ListStudentGroups extends ListRecords
{
    protected static string $resource = StudentGroupResource::class;

    // Группы не создаются преподавателем — кнопку «Создать» не выводим.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
