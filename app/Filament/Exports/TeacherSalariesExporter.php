<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Teacher;
use App\Services\TeacherSalaryService;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * Экспорт сводной ведомости «Зарплаты» по преподавателям. Период берётся
 * текущий месяц (как и виджет-шапка) — экспорт делается из дашборда, где
 * этот же месяц и показан по умолчанию.
 */
class TeacherSalariesExporter extends Exporter
{
    protected static ?string $model = Teacher::class;

    /** @var array<int, array<string, mixed>>|null */
    private static ?array $summary = null;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name')->label('Преподаватель'),
            ExportColumn::make('courses_count')
                ->label('Курсов')
                ->state(fn (Teacher $r): int => self::row($r)['courses_count'] ?? 0),
            ExportColumn::make('earned_period')
                ->label('Начислено за месяц, ₽')
                ->state(fn (Teacher $r): string => number_format(self::row($r)['earned_period_gross'] ?? 0, 2, '.', '')),
            ExportColumn::make('returns_period')
                ->label('Возвраты за месяц, ₽')
                ->state(fn (Teacher $r): string => number_format(self::row($r)['returns_period'] ?? 0, 2, '.', '')),
            ExportColumn::make('earned_all_time')
                ->label('Начислено всего, ₽')
                ->state(fn (Teacher $r): string => number_format(self::row($r)['earned_all_time'] ?? 0, 2, '.', '')),
            ExportColumn::make('paid_all_time')
                ->label('Выплачено всего, ₽')
                ->state(fn (Teacher $r): string => number_format(self::row($r)['paid_all_time'] ?? 0, 2, '.', '')),
            ExportColumn::make('balance')
                ->label('К выплате, ₽')
                ->state(fn (Teacher $r): string => number_format(self::row($r)['balance'] ?? 0, 2, '.', '')),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт ведомости завершён, обработано '.number_format($export->successful_rows).' преподавателей.';
        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Не удалось экспортировать '.number_format($failed).' строк.';
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(Teacher $teacher): array
    {
        self::$summary ??= app(TeacherSalaryService::class)->summaryForAll(now()->format('Y-m'));

        return self::$summary[$teacher->id] ?? [];
    }
}
