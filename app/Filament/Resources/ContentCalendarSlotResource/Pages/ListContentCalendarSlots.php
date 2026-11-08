<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarSlotResource\Pages;

use App\Filament\Resources\ContentCalendarSlotResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContentCalendarSlots extends ListRecords
{
    protected static string $resource = ContentCalendarSlotResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Новый пост'),
        ];
    }
}
