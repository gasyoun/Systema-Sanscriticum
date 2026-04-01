<?php

namespace App\Filament\Imports;

use App\Models\DictionaryWord;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class DictionaryWordImporter extends Importer
{
    protected static ?string $model = DictionaryWord::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('dictionary_id')
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'integer']),
            ImportColumn::make('devanagari')
                ->label('Деванагари (Скрипт)'),
            ImportColumn::make('iast')
                ->label('IAST (Транслитерация)'),
            ImportColumn::make('cyrillic')
                ->label('Кириллица (Чтение)'),
            ImportColumn::make('translation')
                ->requiredMapping()
                ->label('Перевод / Значение'),
            ImportColumn::make('page')
                ->label('Страница/Источник'),
        ];
    }

    public function resolveRecord(): ?DictionaryWord
    {
        return new DictionaryWord();
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Импорт слов завершен. Добавлено: ' . number_format($import->successful_rows) . ' слов.';
        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' Ошибок: ' . number_format($failedRowsCount) . '.';
        }
        return $body;
    }
}