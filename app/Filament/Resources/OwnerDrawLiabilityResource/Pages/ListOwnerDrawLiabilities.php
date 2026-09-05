<?php

declare(strict_types=1);

namespace App\Filament\Resources\OwnerDrawLiabilityResource\Pages;

use App\Filament\Resources\OwnerDrawLiabilityResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListOwnerDrawLiabilities extends ListRecords
{
    protected static string $resource = OwnerDrawLiabilityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
