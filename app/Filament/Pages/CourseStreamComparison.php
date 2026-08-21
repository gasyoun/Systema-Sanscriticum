<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Exports\CourseStreamComparisonExport;
use App\Services\CourseStreamComparisonReport;
use App\Services\TeacherSettlementActPdf;
use App\Support\RoleGate;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * H3083 — «Потоки курса»: потоки одной программы бок о бок.
 *
 * Экран только читает. Вся арифметика — в CourseStreamComparisonReport,
 * страница её только рисует: это условие проверяемости, потому что сервис
 * прогоняется тестом и консольной сверкой `report:verify-stream-comparison`,
 * а страница — нет.
 *
 * Гейт `accounting()` (решение №7 интервью 18-08-2026): на экране видно
 * вознаграждение конкретного преподавателя, и это не для менеджера.
 */
class CourseStreamComparison extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationLabel = 'Потоки курса';

    protected static ?string $navigationGroup = 'Финансы';

    protected static ?int $navigationSort = 45;

    protected static ?string $title = 'Потоки курса';

    protected static ?string $slug = 'course-stream-comparison';

    protected static string $view = 'filament.pages.course-stream-comparison';

    /**
     * Семья по умолчанию. Мария заходит в админку второй раз в жизни — при
     * первом входе ей не надо ничего выбирать (решение раунда 2).
     */
    public const DEFAULT_FAMILY = 'kasmirskii-sivaizm';

    /** Выбранная семья потоков (wire:model.live в blade). */
    public ?string $family = null;

    public static function canAccess(): bool
    {
        return RoleGate::finance();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::finance();
    }

    public function mount(): void
    {
        $families = app(CourseStreamComparisonReport::class)->families();

        $this->family = isset($families[self::DEFAULT_FAMILY])
            ? self::DEFAULT_FAMILY
            : (array_key_first($families) ?: null);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label('Экспорт в Excel')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $this->family !== null)
                ->action(fn (): ?BinaryFileResponse => $this->exportExcel()),

            // H3084 — акт сверки одной страницей, с пустой строкой под решение
            // о доплате. Всё считает сервис отчёта; действие только отдаёт файл.
            Action::make('settlementAct')
                ->label('Акт сверки (PDF)')
                ->icon('heroicon-o-document-text')
                ->visible(fn (): bool => $this->family !== null)
                ->action(fn (): ?StreamedResponse => $this->downloadSettlementAct()),

            AccountantGuide::openAction(),
        ];
    }

    public function downloadSettlementAct(): ?StreamedResponse
    {
        if ($this->family === null) {
            return null;
        }

        $service = app(TeacherSettlementActPdf::class);
        $pdf = $service->make($this->family);
        if ($pdf === null) {
            return null;
        }

        $filename = $service->filename($this->family);

        return response()->streamDownload(fn () => print $pdf->output(), $filename);
    }

    public function exportExcel(): ?BinaryFileResponse
    {
        if ($this->family === null) {
            return null;
        }

        $report = app(CourseStreamComparisonReport::class)->forFamily($this->family);
        if ($report === null) {
            return null;
        }

        return (new CourseStreamComparisonExport($report))->download();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $service = app(CourseStreamComparisonReport::class);

        return [
            'familyOptions' => $service->families(),
            'report' => $this->family ? $service->forFamily($this->family) : null,
        ];
    }
}
