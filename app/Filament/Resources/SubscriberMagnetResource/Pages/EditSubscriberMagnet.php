<?php

declare(strict_types=1);

namespace App\Filament\Resources\SubscriberMagnetResource\Pages;

use App\Filament\Resources\SubscriberMagnetResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSubscriberMagnet extends EditRecord
{
    protected static string $resource = SubscriberMagnetResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\DeleteAction::make()];
    }
}
