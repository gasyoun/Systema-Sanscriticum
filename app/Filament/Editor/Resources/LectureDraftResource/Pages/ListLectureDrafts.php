<?php

declare(strict_types=1);

namespace App\Filament\Editor\Resources\LectureDraftResource\Pages;

use App\Filament\Editor\Resources\LectureDraftResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLectureDrafts extends ListRecords
{
    protected static string $resource = LectureDraftResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()
                ->label('Новая лекция')
                ->icon('heroicon-o-plus'),
        ];
    }
}
