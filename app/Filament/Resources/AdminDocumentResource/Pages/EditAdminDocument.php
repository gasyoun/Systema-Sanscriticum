<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminDocumentResource\Pages;

use App\Filament\Resources\AdminDocumentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAdminDocument extends EditRecord
{
    protected static string $resource = AdminDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('open')
                ->label('Открыть документ')
                ->icon('heroicon-o-arrow-top-right-on-square')
                ->url(fn (): string => $this->record->source_url)
                ->openUrlInNewTab(),

            Actions\DeleteAction::make(),
        ];
    }
}
