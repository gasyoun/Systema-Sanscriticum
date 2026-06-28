<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\Course;
use App\Services\CourseBlockParticipantsReport;
use App\Support\RoleGate;
use Filament\Pages\Page;

/**
 * Дашборд «Участники по блокам»: по выбранному курсу — сколько участников в его
 * группах и сколько студентов оплатили, с разбивкой по блокам и половинам.
 * Чисто справочный экран, расчёты берутся из CourseBlockParticipantsReport.
 */
class CourseBlockParticipants extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Участники по блокам';

    protected static ?string $navigationGroup = 'Пользователи';

    protected static ?int $navigationSort = 75;

    protected static ?string $title = 'Участники по блокам';

    protected static ?string $slug = 'course-block-participants';

    protected static string $view = 'filament.pages.course-block-participants';

    /** Выбранный курс (wire:model.live в blade). */
    public ?int $courseId = null;

    public static function canAccess(): bool
    {
        return RoleGate::adminOnly();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return RoleGate::adminOnly();
    }

    public function mount(): void
    {
        $this->courseId = Course::where('is_active', true)->orderBy('title')->value('id');
    }

    /** @return array<int, string> */
    public function getCourseOptionsProperty(): array
    {
        return Course::orderBy('title')->pluck('title', 'id')->all();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $course = $this->courseId ? Course::find($this->courseId) : null;

        return [
            'courseOptions' => $this->course_options,
            'course' => $course,
            'report' => $course
                ? app(CourseBlockParticipantsReport::class)->forCourse($course)
                : null,
        ];
    }
}
