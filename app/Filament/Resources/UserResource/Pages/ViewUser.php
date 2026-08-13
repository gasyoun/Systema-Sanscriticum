<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Pages\Customer360;
use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\ViewRecord;

class ViewUser extends ViewRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('customer360')
                ->label('Карточка 360')
                ->icon('heroicon-o-user-circle')
                ->url(fn (): string => Customer360::urlForUser($this->record->getKey()))
                ->visible(fn (): bool => Customer360::canAccess()),
            // Уважает UserResource::canEdit() — обычный админ не правит супер-админов.
            Actions\EditAction::make(),
        ];
    }
}
