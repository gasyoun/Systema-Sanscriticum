<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Pages\Customer360;
use App\Filament\Resources\LeadResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditLead extends EditRecord
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('customer360')
                ->label('Карточка 360')
                ->icon('heroicon-o-user-circle')
                ->url(fn (): string => Customer360::urlForLead($this->record->getKey()))
                ->visible(fn (): bool => Customer360::canAccess()),
            Actions\DeleteAction::make(),
        ];
    }
}
