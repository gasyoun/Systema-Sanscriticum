<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\ClassAttendanceService;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Actions;
use Illuminate\Support\Carbon;

/**
 * Консолидированный дашборд посещаемости (GetCourse-паритет GC-B2, H553):
 * rate по студенту/группе/курсу во времени, тренд по неделям, список
 * хронических неявок, экспорт CSV. Только реюз ClassAttendanceService — вся
 * логика подсчёта уже была (forSchedule), здесь только агрегаты.
 */
class AttendanceDashboard extends \Filament\Pages\Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 27;

    protected static ?string $navigationLabel = 'Посещаемость';

    protected static ?string $title = 'Посещаемость';

    protected static ?string $slug = 'attendance-dashboard';

    protected static string $view = 'filament.pages.attendance-dashboard';

    /** Гейт — тот же, что у соседних учебных отчётов (TeacherAnalytics). */
    public static function canAccess(): bool
    {
        if (! config('features.attendance_dashboard')) {
            return false;
        }

        return RoleGate::any(Roles::ADMIN, Roles::TEACHER);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    private function from(): Carbon
    {
        return now()->subDays((int) config('attendance.default_window_days'));
    }

    /** @return array{students: \Illuminate\Support\Collection, groups: \Illuminate\Support\Collection, courses: \Illuminate\Support\Collection, weekly: \Illuminate\Support\Collection, chronic: \Illuminate\Support\Collection} */
    public function report(): array
    {
        return app(ClassAttendanceService::class)->dashboard(
            $this->from(),
            now(),
            (int) config('attendance.chronic_absence_threshold'),
        );
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exportCsv')
                ->label('Экспорт CSV')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => $this->exportCsv()),
        ];
    }

    private function exportCsv()
    {
        $report = $this->report();
        $fileName = 'attendance_'.now()->format('Y-m-d_H-i').'.csv';

        return response()->streamDownload(function () use ($report): void {
            $file = fopen('php://output', 'w');
            fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['Студент', 'Ожидалось занятий', 'Посетил', 'Rate %'], ';');

            foreach ($report['students'] as $row) {
                fputcsv($file, [
                    $row['user']->name,
                    $row['expected'],
                    $row['attended'],
                    $row['rate'],
                ], ';');
            }
            fclose($file);
        }, $fileName);
    }
}
