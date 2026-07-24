<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Models\SrsCard;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * CSV bulk-add for SrsCard (Wave 2, H1487). Field columns are folded into the
 * JSON `fields` snapshot via fillRecordUsing (they are not real DB columns).
 */
class SrsCardImporter extends Importer
{
    protected static ?string $model = SrsCard::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('deck_id')
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'integer', 'exists:srs_decks,id'])
                ->label('ID колоды'),

            ImportColumn::make('devanagari')
                ->label('Деванагари')
                ->fillRecordUsing(function (SrsCard $record, mixed $state): void {
                    $fields = is_array($record->fields) ? $record->fields : [];
                    $fields['devanagari'] = (string) ($state ?? '');
                    $record->fields = $fields;
                }),

            ImportColumn::make('iast')
                ->label('IAST')
                ->fillRecordUsing(function (SrsCard $record, mixed $state): void {
                    $fields = is_array($record->fields) ? $record->fields : [];
                    $fields['iast'] = (string) ($state ?? '');
                    $record->fields = $fields;
                }),

            ImportColumn::make('cyrillic')
                ->label('Кириллица')
                ->fillRecordUsing(function (SrsCard $record, mixed $state): void {
                    $fields = is_array($record->fields) ? $record->fields : [];
                    $fields['cyrillic'] = (string) ($state ?? '');
                    $record->fields = $fields;
                }),

            ImportColumn::make('translation')
                ->requiredMapping()
                ->rules(['required', 'string'])
                ->label('Перевод')
                ->fillRecordUsing(function (SrsCard $record, mixed $state): void {
                    $fields = is_array($record->fields) ? $record->fields : [];
                    $fields['translation'] = (string) ($state ?? '');
                    $record->fields = $fields;
                }),

            ImportColumn::make('direction')
                ->label('Направление (front_back|back_front)')
                ->rules(['nullable', 'in:front_back,back_front']),

            ImportColumn::make('source_word_id')
                ->numeric()
                ->rules(['nullable', 'integer', 'exists:dictionary_words,id'])
                ->label('ID слова словаря'),
        ];
    }

    public function resolveRecord(): ?SrsCard
    {
        $card = new SrsCard;
        $card->fields = [
            'devanagari' => '',
            'iast' => '',
            'cyrillic' => '',
            'translation' => '',
        ];
        $card->direction = 'front_back';

        return $card;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Импорт SRS-карточек завершён. Добавлено: '.number_format($import->successful_rows).'.';
        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' Ошибок: '.number_format($failedRowsCount).'.';
        }

        return $body;
    }
}
