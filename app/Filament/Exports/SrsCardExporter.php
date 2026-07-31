<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\SrsCard;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * CSV export of SRS cards for backup and optional re-upload to Memrise
 * (H2055 / teacher workflow: Systema is source of truth).
 *
 * Columns match {@see \App\Filament\Imports\SrsCardImporter} plus deck slug
 * for human-readable Memrise bulk tools.
 */
class SrsCardExporter extends Exporter
{
    protected static ?string $model = SrsCard::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID карточки'),

            ExportColumn::make('deck_id')
                ->label('ID колоды'),

            ExportColumn::make('deck.name')
                ->label('Колода'),

            ExportColumn::make('deck.slug')
                ->label('Slug колоды'),

            ExportColumn::make('devanagari')
                ->label('Деванагари')
                ->state(fn (SrsCard $record): string => (string) (data_get($record->fields, 'devanagari') ?? '')),

            ExportColumn::make('iast')
                ->label('IAST')
                ->state(fn (SrsCard $record): string => (string) (data_get($record->fields, 'iast') ?? '')),

            ExportColumn::make('cyrillic')
                ->label('Кириллица')
                ->state(fn (SrsCard $record): string => (string) (data_get($record->fields, 'cyrillic') ?? '')),

            ExportColumn::make('translation')
                ->label('Перевод')
                ->state(fn (SrsCard $record): string => (string) (data_get($record->fields, 'translation') ?? '')),

            ExportColumn::make('direction')
                ->label('Направление'),

            ExportColumn::make('source_word_id')
                ->label('ID слова словаря'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт SRS-карточек готов. Строк: '.number_format($export->successful_rows).'.';

        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Ошибок: '.number_format($failed).'.';
        }

        return $body;
    }
}
