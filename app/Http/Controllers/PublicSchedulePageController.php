<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Services\Schedule\FullSchedulePost;
use App\Support\ShopCatalogUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;

/**
 * H4340: публичная страница «Расписание» — https://samskrte.ru/raspisanie.
 * Полные расписания всех активных видимых курсов с предстоящими занятиями
 * тем же билдером, что и Telegram-пост (H4328). Виджет /widgets/schedule
 * остаётся встраиваемой поверхностью для samskrtam.ru/raspisanie — эта
 * страница его не заменяет, а дублирует на человеческом домене.
 *
 * H4647 (MG 13-09-2026): единицы расписания пронумерованы и идут по дню
 * недели ближайшего занятия, начиная с понедельника (ничья по дню — по
 * времени старта, затем по названию; прежде был алфавит по title). Над
 * списком — сводка «сколько курсов и кто ведёт» с якорями на места в
 * списке; списки занятий свёрнуты в гармошку (details/summary в виде).
 */
class PublicSchedulePageController extends Controller
{
    /** Именительный падеж; индекс = Carbon dayOfWeekIso (1 = понедельник). */
    private const WEEKDAYS_RU = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
        6 => 'Суббота',
        7 => 'Воскресенье',
    ];

    public function __invoke(): View
    {
        $courses = collect();
        $teachers = collect();

        if (config('features.schedule_full_post', false)) {
            $courses = Course::query()
                ->where('is_active', true)
                ->where('is_visible', true)
                ->whereHas('schedules', fn ($q) => $q->where('start', '>=', now()))
                ->orderBy('title')
                ->with(['groups:id,name', 'teacher:id,name'])
                ->get()
                ->map(fn (Course $course): array => $this->row($course))
                ->filter(fn (array $row): bool => $row['posts'] !== [])
                ->values()
                ->sort(fn (array $a, array $b): int => $this->compareRows($a, $b))
                ->values()
                ->map(fn (array $row, int $i): array => ['no' => $i + 1] + $row);

            $teachers = $courses
                ->pluck('course.teacher')
                ->filter()
                ->unique('id')
                ->map(fn ($teacher): array => [
                    'name' => $teacher->name,
                    'url' => '/online/prepodavatel/'.ShopCatalogUrl::encodeWords($teacher->name),
                ])
                ->values();
        }

        return view('schedule.page', [
            'courses' => $courses,
            'teachers' => $teachers,
            'flagOn' => config('features.schedule_full_post', false),
        ]);
    }

    /**
     * Строка списка: курс, его посты и навигационные данные — ближайшее
     * предстоящее занятие (по данным постов, т.е. групп + фолбэк-сирот),
     * его день недели и общее число занятий.
     *
     * @return array{course: Course, posts: list<FullSchedulePost>, next: ?Carbon, weekdayIso: int, weekdayRu: ?string, lessonsCount: int}
     */
    private function row(Course $course): array
    {
        $posts = FullSchedulePost::forCourse($course);

        $next = null;
        $lessonsCount = 0;

        foreach ($posts as $post) {
            $lessonsCount += count($post->lessons);

            foreach ($post->lessons as $lesson) {
                if ($lesson['is_past']) {
                    continue;
                }

                $start = Carbon::parse($lesson['start']);
                if ($next === null || $start->lt($next)) {
                    $next = $start;
                }
            }
        }

        return [
            'course' => $course,
            'posts' => $posts,
            'next' => $next,
            'weekdayIso' => $next?->dayOfWeekIso ?? 99,
            'weekdayRu' => $next === null ? null : self::WEEKDAYS_RU[$next->dayOfWeekIso],
            'lessonsCount' => $lessonsCount,
        ];
    }

    /** Пн → Вс; ничья по дню — по времени старта; затем по названию. */
    private function compareRows(array $a, array $b): int
    {
        if ($a['weekdayIso'] !== $b['weekdayIso']) {
            return $a['weekdayIso'] <=> $b['weekdayIso'];
        }

        $an = $a['next'];
        $bn = $b['next'];

        if ($an instanceof Carbon && $bn instanceof Carbon && ! $an->equalTo($bn)) {
            return $an->getTimestamp() <=> $bn->getTimestamp();
        }

        return $a['course']->title <=> $b['course']->title;
    }
}
