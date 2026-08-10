<?php

namespace App\Filament\Resources\CourseMaterialResource\Pages;

use App\Filament\Resources\CourseMaterialResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCourseMaterial extends CreateRecord
{
    protected static string $resource = CourseMaterialResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Кто завёл строку — видно в реестре, а не только в логах.
        $data['added_by_user_id'] ??= auth()->id();

        return $data;
    }
}
