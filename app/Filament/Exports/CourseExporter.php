<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Course;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class CourseExporter extends Exporter
{
    protected static ?string $model = Course::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),

            ExportColumn::make('title')
                ->label('Название'),

            ExportColumn::make('slug')
                ->label('URL (slug)'),

            ExportColumn::make('description')
                ->label('Описание'),

            ExportColumn::make('chat_url')
                ->label('Ссылка на чат'),

            ExportColumn::make('lessons_count')
                ->label('Кол-во уроков'),

            ExportColumn::make('hours_count')
                ->label('Академ. часов'),

            ExportColumn::make('salary_type')
                ->label('Схема ЗП')
                ->formatStateUsing(fn (?string $state): string => match ($state) {
                    'percent'           => 'Процент от продаж',
                    'fix_per_student'   => 'Фикс за студента',
                    'fix_total'         => 'Фикс за курс',
                    'percent_per_block' => 'Процент с блока',
                    'fix_per_block'     => 'Фикс за блок',
                    default             => '—',
                }),

            ExportColumn::make('salary_value')
                ->label('Ставка'),

            ExportColumn::make('is_visible')
                ->label('Виден на сайте')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет'),

            ExportColumn::make('is_active')
                ->label('Активен в ЛК')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет'),

            ExportColumn::make('is_elective')
                ->label('Факультатив')
                ->formatStateUsing(fn (bool $state): string => $state ? 'Да' : 'Нет'),

            ExportColumn::make('created_at')
                ->label('Создан'),

            ExportColumn::make('updated_at')
                ->label('Обновлён'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт курсов завершён, обработано ' . number_format($export->successful_rows) . ' записей.';

        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Не удалось экспортировать ' . number_format($failed) . ' строк.';
        }

        return $body;
    }
}