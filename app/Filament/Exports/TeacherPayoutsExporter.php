<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\TeacherPayout;
use App\Services\TeacherSalaryService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Экспорт истории выплат преподавателям (раздел «История выплат»).
 */
class TeacherPayoutsExporter extends Exporter
{
    protected static ?string $model = TeacherPayout::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('teacher.name')->label('Преподаватель'),
            ExportColumn::make('amount')
                ->label('Сумма, ₽')
                ->state(fn (TeacherPayout $r): string => number_format((float) $r->amount, 2, '.', '')),
            ExportColumn::make('paid_at')
                ->label('Дата выплаты')
                ->state(fn (TeacherPayout $r): ?string => $r->paid_at?->format('d.m.Y')),
            ExportColumn::make('period_month')->label('Период'),
            ExportColumn::make('course.title')->label('Курс'),
            ExportColumn::make('salary_type')
                ->label('Схема (снимок)')
                ->state(fn (TeacherPayout $r): string => TeacherSalaryService::salaryTypeLabel($r->salary_type)),
            ExportColumn::make('salary_value')->label('Ставка (снимок)'),
            ExportColumn::make('comment')->label('Комментарий'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт выплат завершён, обработано '.number_format($export->successful_rows).' записей.';
        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Не удалось экспортировать '.number_format($failed).' строк.';
        }

        return $body;
    }
}
