<?php

declare(strict_types=1);

namespace App\Filament\Resources\ProductDocResource\Pages;

use App\Filament\Resources\ProductDocResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListProductDocs extends ListRecords
{
    protected static string $resource = ProductDocResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Добавить'),
        ];
    }
}
