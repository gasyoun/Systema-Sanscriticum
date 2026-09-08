<?php

declare(strict_types=1);

namespace App\Filament\Resources\CourseMaterialSubmissionResource\Pages;

use App\Filament\Resources\CourseMaterialSubmissionResource;
use Filament\Resources\Pages\ListRecords;

class ListCourseMaterialSubmissions extends ListRecords
{
    protected static string $resource = CourseMaterialSubmissionResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
