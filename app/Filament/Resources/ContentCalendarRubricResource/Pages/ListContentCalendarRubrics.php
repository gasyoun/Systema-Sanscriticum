<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarRubricResource\Pages;

use App\Filament\Resources\ContentCalendarRubricResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListContentCalendarRubrics extends ListRecords
{
    protected static string $resource = ContentCalendarRubricResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make()->label('Новая рубрика'),
        ];
    }
}
