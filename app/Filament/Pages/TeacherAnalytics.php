<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Widgets\DisciplineByGroupWidget;
use App\Services\TeacherAnalytics as TeacherAnalyticsService;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class TeacherAnalytics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Обучение';

    protected static ?int $navigationSort = 26;

    protected static ?string $navigationLabel = 'Аналитика студентов';

    protected static ?string $title = 'Аналитика студентов';

    protected static ?string $slug = 'teacher-analytics';

    protected static string $view = 'filament.pages.teacher-analytics';

    /** Преподаватель видит свою аналитику; админ-подобные — общую (все курсы). */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        // Fail-closed (тот же паттерн, что TeacherCoursePayments, PR #3032):
        // роль teacher без карточки преподавателя (users.teacher_id = null,
        // например после удаления Teacher — FK nullOnDelete, роль остаётся)
        // НЕ получает доступ. Ветка «видно всё» — строго админ-подобные.
        return (bool) (($user?->isTeacher() && $user->teacher_id !== null) || $user?->isAdminLike());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** Несуществующий id: пустой скоуп для вырожденного случая teacher без карточки. */
    private const EMPTY_SCOPE = -1;

    /** teacher_id для скоупа: у преподавателя — свой; null — только админ-подобные (все курсы). */
    private function scopeTeacherId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->isTeacher() && ! $user->isAdminLike()) {
            // teacher без карточки: null НЕ должен означать «всё» — пустой скоуп
            return $user->teacher_id ?? self::EMPTY_SCOPE;
        }

        return null;
    }

    private function analytics(): TeacherAnalyticsService
    {
        return TeacherAnalyticsService::for($this->scopeTeacherId());
    }

    /** @return array{opened: int, completed_any: int, lessons_total: int, views_completed: int} */
    public function funnel(): array
    {
        return $this->analytics()->funnel();
    }

    /** @return array<string, int> */
    public function dailyOpens(): array
    {
        return $this->analytics()->dailyOpens(30);
    }

    public function studentProgress(): Collection
    {
        return $this->analytics()->studentProgress();
    }

    public function webinarAttendance(): Collection
    {
        return $this->analytics()->webinarAttendance();
    }

    protected function getHeaderWidgets(): array
    {
        return [DisciplineByGroupWidget::class];
    }
}
