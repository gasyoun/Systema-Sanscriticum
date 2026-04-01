<?php

namespace App\Filament\Exports;

use App\Models\Lesson;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class LessonExporter extends Exporter
{
    protected static ?string $model = Lesson::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('course.id')->label('ID Курса'),
            ExportColumn::make('course.title')->label('Название Курса'),
            ExportColumn::make('id')->label('ID Урока'),
            ExportColumn::make('block_number')->label('Номер блока'),
            ExportColumn::make('title')->label('Название Урока'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт уроков завершен. Выгружено строк: ' . number_format($export->successful_rows) . '.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' Строк с ошибками: ' . number_format($failedRowsCount) . '.';
        }

        return $body;
    }
}