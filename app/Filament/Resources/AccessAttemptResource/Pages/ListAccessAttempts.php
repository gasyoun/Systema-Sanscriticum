<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessAttemptResource\Pages;

use App\Filament\Resources\AccessAttemptResource;
use Filament\Resources\Pages\ListRecords;

class ListAccessAttempts extends ListRecords
{
    protected static string $resource = AccessAttemptResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
