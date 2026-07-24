<?php

declare(strict_types=1);

namespace App\Filament\Resources\SrsCardResource\Pages;

use App\Filament\Resources\SrsCardResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditSrsCard extends EditRecord
{
    protected static string $resource = SrsCardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
