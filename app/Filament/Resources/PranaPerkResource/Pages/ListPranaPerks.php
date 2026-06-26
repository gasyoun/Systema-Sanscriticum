<?php

declare(strict_types=1);

namespace App\Filament\Resources\PranaPerkResource\Pages;

use App\Filament\Resources\PranaPerkResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListPranaPerks extends ListRecords
{
    protected static string $resource = PranaPerkResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
