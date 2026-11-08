<?php

declare(strict_types=1);

namespace App\Filament\Resources\ContentCalendarRubricResource\Pages;

use App\Filament\Resources\ContentCalendarRubricResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContentCalendarRubric extends CreateRecord
{
    protected static string $resource = ContentCalendarRubricResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
