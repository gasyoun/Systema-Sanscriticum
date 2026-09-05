<?php

declare(strict_types=1);

namespace App\Filament\Resources\IpExpenseResource\Pages;

use App\Filament\Resources\IpExpenseResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListIpExpenses extends ListRecords
{
    protected static string $resource = IpExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // MVP-ввод с телефона: тот же день — одна кнопка.
            Actions\CreateAction::make()->label('Внести расход'),
        ];
    }
}
