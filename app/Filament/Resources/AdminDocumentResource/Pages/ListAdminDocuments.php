<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminDocumentResource\Pages;

use App\Filament\Resources\AdminDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListAdminDocuments extends ListRecords
{
    protected static string $resource = AdminDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Добавить файл'),
        ];
    }
}
