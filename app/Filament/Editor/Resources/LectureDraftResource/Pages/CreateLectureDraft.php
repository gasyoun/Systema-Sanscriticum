<?php

declare(strict_types=1);

namespace App\Filament\Editor\Resources\LectureDraftResource\Pages;

use App\Filament\Editor\Resources\LectureDraftResource;
use App\Models\LectureDraft;
use Filament\Resources\Pages\CreateRecord;

class CreateLectureDraft extends CreateRecord
{
    protected static string $resource = LectureDraftResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();
        $data['status'] = LectureDraft::STATUS_DRAFT;
        return $data;
    }

    protected function getRedirectUrl(): string
    {
        // После создания — на страницу редактирования (там покажется шаг загрузки файлов)
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
