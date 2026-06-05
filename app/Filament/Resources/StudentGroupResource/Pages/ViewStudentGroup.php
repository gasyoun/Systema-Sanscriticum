<?php

namespace App\Filament\Resources\StudentGroupResource\Pages;

use App\Filament\Resources\StudentGroupResource;
use Filament\Resources\Pages\ViewRecord;

class ViewStudentGroup extends ViewRecord
{
    protected static string $resource = StudentGroupResource::class;

    // Только просмотр — без кнопки «Редактировать».
    protected function getHeaderActions(): array
    {
        return [];
    }
}
