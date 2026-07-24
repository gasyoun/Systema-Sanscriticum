<?php

declare(strict_types=1);

namespace App\Filament\Resources\SrsDeckResource\Pages;

use App\Filament\Resources\SrsDeckResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListSrsDecks extends ListRecords
{
    protected static string $resource = SrsDeckResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Новая колода'),
        ];
    }
}
