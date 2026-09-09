<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Models\Lesson;
use App\Services\ClassAttendanceService;
use App\Services\Schedule\CanvasMoney;
use App\Services\Schedule\TextbookScale;
use App\Support\RoleGate;
use App\Support\Roles;
use Filament\Actions;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Консолидированный дашборд посещаемости (GetCourse-паритет GC-B2, H553):
 * rate по студенту/группе/курсу во времени, тренд по неделям, список
 * хронических неявок, экспорт CSV. Только реюз ClassAttendanceService — вся
 * логика подсчёта уже была (forSchedule), здесь только агрегаты.
 */
class AttendanceDashboard extends Page
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

    /** @return array{students: Collection, groups: Collection, courses: Collection, weekly: Collection, chronic: Collection} */
    public function report(): array
    {
        return app(ClassAttendanceService::class)->dashboard(
            $this->from(),
            now(),
            (int) config('attendance.chronic_absence_threshold'),
        );
    }

    /**
     * H4443 (MG 09-09): «ещё в деньгах» по идущим грамматикам — неоплаченные
     * блоки студентов от курсора канвы. Админ-only поверхность, в TG-пост
     * деньги не попадают.
     *
     * @return array{rows: list<array{course: string, group: string, cursor_block: int, blocks_total: int, unpaid: float, students: int}>, total: float}
     */
    public function canvasMoney(): array
    {
        $courses = Course::query()
            ->where('is_active', true)->where('is_visible', true)
            ->whereHas('groups')
            ->with('groups.users')
            ->orderBy('title')->get();

        $rows = [];
        $grand = 0.0;

        foreach ($courses as $course) {
            $family = TextbookScale::courseFamilyPublic((string) $course->title);
            if ($family === null) {
                continue;
            }
            $total = TextbookScale::families()[$family]['total'];
            $lessons = Lesson::where('course_id', $course->id)
                ->whereNotNull('lesson_date')->orderBy('lesson_date')->get();
            $cursor = TextbookScale::cursor($lessons, $family);
            if ($cursor === 0) {
                continue;
            }

            $cursorBlock = 0;
            foreach ($lessons as $l) {
                $chitki = array_filter(
                    TextbookScale::parseTitle((string) $l->title),
                    fn (array $i): bool => $i['family'] === $family && $i['kind'] === 'chitka',
                );
                if ($chitki !== [] && max(array_column($chitki, 'lesson')) === $cursor) {
                    $cursorBlock = TextbookScale::parseBlockMarker((string) $l->title)
                        ?? (int) ceil($cursor / TextbookScale::lessonsPerBlock());
                    break;
                }
            }
            $blocksTotal = TextbookScale::blocksTotal($course->id, $total);

            $unpaid = 0.0;
            $students = collect();
            foreach ($course->groups as $group) {
                foreach ($group->users as $user) {
                    $students->push($user);
                    $u = CanvasMoney::unpaidFor($user, $course, $cursorBlock, $blocksTotal);
                    $unpaid += $u['amount'];
                }
            }

            if ($students->isEmpty()) {
                continue;
            }

            $grand += $unpaid;
            $rows[] = [
                'course' => (string) $course->title,
                'group' => $course->groups->pluck('name')->implode(', '),
                'cursor_block' => $cursorBlock,
                'blocks_total' => $blocksTotal,
                'unpaid' => round($unpaid, 2),
                'students' => $students->unique('id')->count(),
            ];
        }

        return ['rows' => $rows, 'total' => round($grand, 2)];
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
