<?php

declare(strict_types=1);

namespace App\Filament\Resources\StudentAvailabilityWindowResource\Pages;

use App\Filament\Resources\StudentAvailabilityWindowResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListStudentAvailabilityWindows extends ListRecords
{
    protected static string $resource = StudentAvailabilityWindowResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
