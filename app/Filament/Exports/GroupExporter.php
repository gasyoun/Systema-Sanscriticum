<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Group;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Database\Eloquent\Builder;

class GroupExporter extends Exporter
{
    protected static ?string $model = Group::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),

            ExportColumn::make('name')
                ->label('Название группы'),

            ExportColumn::make('users_count')
                ->label('Кол-во учеников')
                ->counts('users'),

            ExportColumn::make('users_list')
                ->label('Ученики')
                ->state(function (Group $record): string {
                    // Безопасная проверка — если связь по какой-то причине не загружена,
                    // подгружаем её прямо тут, чтобы не упасть с ошибкой
                    if (! $record->relationLoaded('users')) {
                        $record->load('users:id,name');
                    }

                    return $record->users
                        ->sortBy('name')
                        ->pluck('name')
                        ->filter()
                        ->implode(', ');
                }),

            ExportColumn::make('created_at')
                ->label('Создана'),

            ExportColumn::make('updated_at')
                ->label('Обновлена'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт групп завершён, обработано ' . number_format($export->successful_rows) . ' записей.';

        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Не удалось экспортировать ' . number_format($failed) . ' строк.';
        }

        return $body;
    }
}