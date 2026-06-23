<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Экспорт студентов, подключивших бота (TG и/или VK), со временем привязки.
 * Запрос сужается до подключённых в самой кнопке (ExportAction->modifyQueryUsing).
 */
class ConnectedBotUsersExporter extends Exporter
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name')->label('Имя'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('phone')->label('Телефон'),

            ExportColumn::make('has_telegram')
                ->label('Telegram')
                ->state(fn (User $r): string => $r->telegram_id ? 'да' : ''),
            ExportColumn::make('telegram_connected_at')
                ->label('TG подключён')
                ->state(fn (User $r): ?string => $r->telegram_connected_at
                    ? \Carbon\Carbon::parse($r->telegram_connected_at)->format('d.m.Y H:i')
                    : null),

            ExportColumn::make('has_vk')
                ->label('VK')
                ->state(fn (User $r): string => $r->vk_id ? 'да' : ''),
            ExportColumn::make('vk_connected_at')
                ->label('VK подключён')
                ->state(fn (User $r): ?string => $r->vk_connected_at
                    ? \Carbon\Carbon::parse($r->vk_connected_at)->format('d.m.Y H:i')
                    : null),

            ExportColumn::make('groups')
                ->label('Группы')
                ->state(fn (User $r): string => $r->groups->pluck('name')->implode(', ')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт подключивших бота завершён, обработано '.number_format($export->successful_rows).' записей.';
        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Не удалось экспортировать '.number_format($failed).' строк.';
        }

        return $body;
    }
}
