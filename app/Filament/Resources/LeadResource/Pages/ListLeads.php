<?php

namespace App\Filament\Resources\LeadResource\Pages;

use App\Filament\Resources\LeadResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLeads extends ListRecords
{
    protected static string $resource = LeadResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Стандартная кнопка "Создать"
            Actions\CreateAction::make(),
            
            // === НАША НОВАЯ КНОПКА ===
            Actions\Action::make('export')
                ->label('Скачать Excel')
                ->color('success') // Зеленая кнопка
                ->icon('heroicon-o-arrow-down-tray') // Иконка скачивания
                ->url(route('leads.export')) // Ссылка на наш маршрут
                ->openUrlInNewTab(), // Открывать в новой вкладке (на всякий случай)
        ];
    }
}