<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminDocumentResource\Pages;

use App\Filament\Resources\AdminDocumentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminDocument extends CreateRecord
{
    protected static string $resource = AdminDocumentResource::class;

    /**
     * Автора строки ставим здесь, а не в форме: скрытое поле в схеме можно
     * подделать из браузера, а этот хук читает только сессию.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['added_by_user_id'] = auth()->id();

        return $data;
    }
}
