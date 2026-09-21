<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\Schedule;
use App\Services\Schedule\FullSchedulePost;
use App\Services\Schedule\TextbookScale;
use App\Support\ShopCatalogUrl;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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
     * H5233: /raspisanie/kochergina — карточка на каждую живую группу
     * семейства Кочергиной с канва-курсором «Урок N из total» и кликом на
     * заявку (/interest с префиллом интента). Живая группа — та же семантика,
     * что в WeeklyFinishReport::build: есть прошедшее занятие И есть
     * предстоящее. Курсор — TextbookScale::cursor по записям уроков курса
     * (read-only сервис, та же семантика, что у канвы в TG-посте).
     */
    public function groups(): View
    {
        $flagOn = config('features.schedule_full_post', false);
        $rows = $flagOn ? $this->kocherginaRows() : collect();

        return view('schedule.groups', [
            'rows' => $rows,
            'flagOn' => $flagOn,
        ]);
    }

    /**
     * Строки карточек: по одной на живую группу курса семейства Кочергиной.
     *
     * @return Collection<int, array{course: Course, groupName: string, canvasCursor: int, canvasTotal: int, teachers: list<array{name: string, url: string}>, interestUrl: string, joinIntent: string}>
     */
    private function kocherginaRows(): Collection
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereHas('groups')
            ->where('title', 'like', '%Кочерг%')
            ->with(['groups:id,name', 'teacher:id,name'])
            ->orderBy('title')
            ->get()
            ->filter(fn (Course $course): bool => TextbookScale::courseFamilyPublic((string) $course->title) === 'kochergina');

        // Курсы семейства, где студент уже сидит: для карточки ЧУЖОГО курса
        // того же семейства клик префиллится intent=transfer (H5233).
        $myFamilyCourseIds = $this->studentKocherginaCourseIds();

        $rows = collect();

        foreach ($courses as $course) {
            $lessons = Lesson::query()
                ->where('course_id', $course->id)
                ->whereNotNull('lesson_date')
                ->orderBy('lesson_date')
                ->get();
            $cursor = TextbookScale::cursor($lessons, 'kochergina');
            $total = (int) TextbookScale::families()['kochergina']['total'];

            $teachers = [];
            if ($course->teacher !== null) {
                $teachers[] = [
                    'name' => $course->teacher->name,
                    'url' => '/online/prepodavatel/'.ShopCatalogUrl::encodeWords($course->teacher->name),
                ];
            }

            $intent = $myFamilyCourseIds->contains(fn (int $id): bool => $id !== (int) $course->id)
                ? 'transfer'
                : 'join';
            $interestUrl = route('course-interest.show', ['course' => $course->slug]).'?intent='.$intent;

            foreach ($course->groups as $group) {
                $sessions = $this->groupSessions($group);
                $hasPast = $sessions->contains(fn (Schedule $s): bool => $this->isPast($s));
                $hasFuture = $sessions->contains(
                    fn (Schedule $s): bool => $s->start !== null && $s->start->isFuture(),
                );

                if (! $hasPast || ! $hasFuture) {
                    continue; // не живая: ещё не стартовала или закончилась
                }

                $rows->push([
                    'course' => $course,
                    'groupName' => (string) $group->name,
                    'canvasCursor' => $cursor,
                    'canvasTotal' => $total,
                    'teachers' => $teachers,
                    'interestUrl' => $interestUrl,
                    'joinIntent' => $intent,
                ]);
            }
        }

        return $rows->sortBy(fn (array $row): string => $row['course']->title.'|'.$row['groupName'])->values();
    }

    /**
     * Курсовые id семейства Кочергиной, к чьим группам студент активен
     * (left_at IS NULL) — основа интента transfer. Гость — пусто.
     *
     * @return Collection<int, int>
     */
    private function studentKocherginaCourseIds(): Collection
    {
        $user = auth()->user();
        if ($user === null) {
            return collect();
        }

        return $user->groups()
            ->wherePivotNull('left_at')
            ->whereHas('courses', fn ($q) => $q->where('is_active', true)->where('title', 'like', '%Кочерг%'))
            ->with('courses:id,title')
            ->get()
            ->flatMap(fn ($group) => $group->courses)
            ->filter(fn (Course $course): bool => TextbookScale::courseFamilyPublic((string) $course->title) === 'kochergina')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();
    }

    /** Сессии группы (та же семантика, что в WeeklyFinishReport::groupSessions). */
    private function groupSessions($group): Collection
    {
        $courseIds = $group->courses()->pluck('courses.id');

        return Schedule::query()
            ->whereNotNull('start')
            ->where(function ($q) use ($group, $courseIds): void {
                $q->where('group_id', $group->id)
                    ->orWhere(fn ($q2) => $q2->whereNull('group_id')->whereIn('course_id', $courseIds));
            })
            ->orderBy('start')
            ->get();
    }

    /** «Прошло» по эффективному концу — та же семантика, что у WeeklyFinishReport. */
    private function isPast(Schedule $s): bool
    {
        return $s->start !== null
            && ($s->end ?? $s->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS))->isPast();
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
