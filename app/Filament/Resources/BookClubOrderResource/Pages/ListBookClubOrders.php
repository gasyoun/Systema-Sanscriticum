<?php

declare(strict_types=1);

namespace App\Filament\Resources\BookClubOrderResource\Pages;

use App\Filament\Resources\BookClubOrderResource;
use App\Models\BookClubOrder;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListBookClubOrders extends ListRecords
{
    protected static string $resource = BookClubOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // CSV для печати этикеток/передачи на почту: UTF-8 с BOM и «;» —
            // так Excel открывает кириллицу без танцев с кодировкой.
            Actions\Action::make('exportCsv')
                ->label('Экспорт CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->action(function () {
                    $filename = 'book-club-orders-'.now()->format('Y-m-d-His').'.csv';

                    return response()->streamDownload(function (): void {
                        $out = fopen('php://output', 'wb');
                        fwrite($out, "\xEF\xBB\xBF");
                        fputcsv($out, [
                            '№', 'Создан', 'Режим', 'Книга', 'Экз.', 'Покупатель', 'Телефон',
                            'Получатель', 'Страна', 'Город', 'Индекс', 'Адрес',
                            'Сумма ₽', 'Доставка ₽', 'Статус', 'Трек-номер',
                        ], ';');

                        BookClubOrder::query()
                            ->with(['user', 'book'])
                            ->orderBy('id')
                            ->chunk(200, function ($orders) use ($out): void {
                                foreach ($orders as $order) {
                                    fputcsv($out, [
                                        $order->id,
                                        optional($order->created_at)->format('d.m.Y H:i'),
                                        match ($order->mode) {
                                            BookClubOrder::MODE_PICKUP => 'Самовывоз',
                                            BookClubOrder::MODE_PURCHASE => 'Покупка',
                                            default => 'Доставка кампании',
                                        },
                                        $order->book?->title,
                                        $order->books_count,
                                        $order->user?->email,
                                        $order->phone,
                                        $order->recipient_name,
                                        $order->country_name ?: $order->country_code,
                                        $order->city,
                                        $order->postal_code,
                                        $order->address_line,
                                        $order->amount,
                                        $order->delivery_amount,
                                        $order->statusLabel(),
                                        $order->tracking_number,
                                    ], ';');
                                }
                            });

                        fclose($out);
                    }, $filename);
                }),
        ];
    }
}
