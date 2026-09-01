<?php

namespace App\Filament\Resources\CourseWaitlistItemResource\Pages;

use App\Filament\Resources\CourseWaitlistItemResource;
use Filament\Resources\Pages\ListRecords;

class ListCourseWaitlistItems extends ListRecords
{
    protected static string $resource = CourseWaitlistItemResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
