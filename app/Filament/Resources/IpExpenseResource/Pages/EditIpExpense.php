<?php

declare(strict_types=1);

namespace App\Filament\Resources\IpExpenseResource\Pages;

use App\Filament\Resources\IpExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditIpExpense extends EditRecord
{
    protected static string $resource = IpExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // DeleteAction намеренно нет: контур append-only (H4188).
            Actions\DeleteAction::make()->hidden(),
        ];
    }
}
