<?php

declare(strict_types=1);

namespace App\Filament\Pages;

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

        return (bool) ($user?->isTeacher() || $user?->isAdminLike());
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** teacher_id для скоупа: у преподавателя — свой; у админа — null (все курсы). */
    private function scopeTeacherId(): ?int
    {
        $user = auth()->user();
        if ($user && $user->isTeacher() && ! $user->isAdminLike()) {
            return $user->teacher_id;
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
}
