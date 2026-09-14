<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\CertificateMilestone;
use App\Models\User;
use App\Services\UnissuedCertificatesReport;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

/**
 * H3914: CSV-выгрузка отчёта «невыданные дипломы/сертификаты» для куратора.
 * Ряд экспорта = строка отчёта (студент × веха × итерация) из
 * UnissuedCertificatesReport::query() — только чтение.
 */
class UnissuedCertificatesExporter extends Exporter
{
    protected static ?string $model = User::class;

    /** @var array<int, string>|null */
    private static ?array $courseTitles = null;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID студента'),
            ExportColumn::make('name')->label('Имя'),
            ExportColumn::make('email')->label('Email'),
            ExportColumn::make('phone')->label('Телефон'),
            ExportColumn::make('telegram_id')->label('TG'),
            ExportColumn::make('vk_id')->label('VK'),

            ExportColumn::make('course_title')
                ->label('Курс')
                ->state(fn (User $r): string => self::courseTitles()[(int) $r->course_id] ?? ''),

            ExportColumn::make('document_type')
                ->label('Документ')
                ->formatStateUsing(fn (string $state): string => CertificateMilestone::DOCUMENT_TYPES[$state] ?? $state),

            ExportColumn::make('milestone_title')->label('Веха'),
            ExportColumn::make('group_name')->label('Группа'),
            ExportColumn::make('trigger_date')->label('Триггер созрел'),
            ExportColumn::make('occurrence')->label('Итерация'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Экспорт «невыданные дипломы» завершён, обработано '.number_format($export->successful_rows).' записей.';
        if ($failed = $export->getFailedRowsCount()) {
            $body .= ' Не удалось экспортировать '.number_format($failed).' строк.';
        }

        return $body;
    }

    /** @return array<int, string> */
    private static function courseTitles(): array
    {
        return self::$courseTitles ??= app(UnissuedCertificatesReport::class)->courseTitles();
    }
}
