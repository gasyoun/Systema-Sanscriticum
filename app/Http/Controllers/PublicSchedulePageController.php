<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\Schedule\FullSchedulePost;
use Illuminate\Contracts\View\View;

/**
 * H4331: публичная страница «Расписание» — https://samskrte.ru/raspisanie.
 * Полные расписания всех активных видимых курсов с предстоящими занятиями
 * тем же билдером, что и Telegram-пост (H4328). Виджет /widgets/schedule
 * остаётся встраиваемой поверхностью для samskrtam.ru/raspisanie — эта
 * страница его не заменяет, а дублирует на человеческом домене.
 */
class PublicSchedulePageController extends Controller
{
    public function __invoke(): View
    {
        $courses = collect();

        if (config('features.schedule_full_post', false)) {
            $courses = Course::query()
                ->where('is_active', true)
                ->where('is_visible', true)
                ->whereHas('schedules', fn ($q) => $q->where('start', '>=', now()))
                ->orderBy('title')
                ->with(['groups:id,name', 'teacher:id,name'])
                ->get()
                ->map(fn (Course $course): array => [
                    'course' => $course,
                    'posts' => FullSchedulePost::forCourse($course),
                ])
                ->filter(fn (array $row): bool => $row['posts'] !== [])
                ->values();
        }

        return view('schedule.page', [
            'courses' => $courses,
            'flagOn' => config('features.schedule_full_post', false),
        ]);
    }
}
