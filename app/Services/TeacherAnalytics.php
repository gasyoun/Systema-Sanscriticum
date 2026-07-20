<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonView;
use App\Models\WebinarAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Аналитика по студентам конкретного преподавателя: посуточная воронка просмотров
 * и прогресс студентов. Все сигналы берутся из lesson_views (одна строка на
 * студента+урок: first_opened_at — момент первого открытия, is_completed — факт
 * прохождения). teacherId=null → агрегат по всем курсам (для админа).
 */
class TeacherAnalytics
{
    public function __construct(private ?int $teacherId = null) {}

    public static function for(?int $teacherId): self
    {
        return new self($teacherId);
    }

    /** ID курсов преподавателя (или все, если teacherId не задан). */
    public function courseIds(): Collection
    {
        return Course::query()
            ->when($this->teacherId !== null, fn ($q) => $q->where('teacher_id', $this->teacherId))
            ->pluck('id');
    }

    /**
     * Посуточная воронка просмотров: сколько уроков ВПЕРВЫЕ открыли по дням
     * (first_opened_at) за последние $days дней. Возвращает дату→счётчик за все
     * дни периода (нули включительно), от старых к новым.
     *
     * @return array<string, int>
     */
    public function dailyOpens(int $days = 30): array
    {
        $courseIds = $this->courseIds();
        $since = now()->subDays($days - 1)->startOfDay();

        $counts = LessonView::query()
            ->whereIn('course_id', $courseIds)
            ->where('first_opened_at', '>=', $since)
            ->selectRaw('DATE(first_opened_at) as d, COUNT(*) as c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = $since->copy()->addDays($i)->toDateString();
            $series[$date] = (int) ($counts[$date] ?? 0);
        }

        return $series;
    }

    /**
     * Воронка по студентам преподавателя.
     *
     * @return array{opened: int, completed_any: int, lessons_total: int, views_completed: int}
     */
    public function funnel(): array
    {
        $courseIds = $this->courseIds();

        $opened = LessonView::query()->whereIn('course_id', $courseIds)->distinct()->count('user_id');
        $completedAny = LessonView::query()->whereIn('course_id', $courseIds)
            ->where('is_completed', true)->distinct()->count('user_id');
        $viewsCompleted = LessonView::query()->whereIn('course_id', $courseIds)
            ->where('is_completed', true)->count();
        $lessonsTotal = Lesson::query()->whereIn('course_id', $courseIds)->count();

        return [
            'opened' => $opened,
            'completed_any' => $completedAny,
            'lessons_total' => $lessonsTotal,
            'views_completed' => $viewsCompleted,
        ];
    }

    /**
     * Прогресс каждого студента по курсам преподавателя (меньше всего прошедшие —
     * сверху, чтобы преподаватель видел, кому нужна помощь).
     *
     * @return Collection<int, array{user_id: int, name: string, completed: int, total: int, percent: int, last_seen: ?string}>
     */
    public function studentProgress(): Collection
    {
        $courseIds = $this->courseIds();
        $totalLessons = Lesson::query()->whereIn('course_id', $courseIds)->count();

        return LessonView::query()
            ->whereIn('lesson_views.course_id', $courseIds)
            ->join('users', 'users.id', '=', 'lesson_views.user_id')
            ->groupBy('lesson_views.user_id', 'users.name')
            ->selectRaw('lesson_views.user_id as user_id, users.name as name')
            ->selectRaw('SUM(lesson_views.is_completed) as completed')
            ->selectRaw('MAX(lesson_views.last_opened_at) as last_seen')
            ->get()
            ->map(function ($row) use ($totalLessons): array {
                $completed = (int) $row->completed;

                return [
                    'user_id' => (int) $row->user_id,
                    'name' => (string) $row->name,
                    'completed' => $completed,
                    'total' => $totalLessons,
                    'percent' => $totalLessons > 0 ? (int) round($completed / $totalLessons * 100) : 0,
                    'last_seen' => $row->last_seen ? Carbon::parse($row->last_seen)->toDateTimeString() : null,
                ];
            })
            ->sortBy('percent')
            ->values();
    }

    /**
     * Посещаемость вебинаров (из webinar_attendances, Zoom participant-вебхуки):
     * по одной строке на вебинар-занятие курсов преподавателя, новые сверху.
     * `attendees` — уникальные участники (студент по user_id, гость по uuid-сессии),
     * `known` — из них сопоставленные студенты, `avg_minutes` — средняя длительность.
     *
     * @return Collection<int, array{schedule_id: int, title: string, start: ?string, attendees: int, known: int, avg_minutes: ?int}>
     */
    public function webinarAttendance(int $limit = 20): Collection
    {
        $courseIds = $this->courseIds();

        return WebinarAttendance::query()
            ->join('schedules', 'schedules.id', '=', 'webinar_attendances.schedule_id')
            ->whereIn('schedules.course_id', $courseIds)
            ->groupBy('schedules.id', 'schedules.title', 'schedules.start')
            ->selectRaw('schedules.id as schedule_id, schedules.title as title, schedules.start as start')
            // COALESCE(user_id, uuid): студент дедуплицируется по user_id, гость — по сессии.
            ->selectRaw('COUNT(DISTINCT COALESCE(webinar_attendances.user_id, webinar_attendances.zoom_participant_uuid)) as attendees')
            ->selectRaw('COUNT(DISTINCT webinar_attendances.user_id) as known') // NULL-гости не считаются
            ->selectRaw('AVG(webinar_attendances.duration_seconds) as avg_dur')
            // Студенты, перешедшие по трекинг-ссылке (надёжная привязка, даже если
            // Zoom не опознал их по email).
            ->selectRaw('(SELECT COUNT(*) FROM schedule_join_clicks sjc WHERE sjc.schedule_id = schedules.id) as clicked')
            ->orderByDesc('schedules.start')
            ->limit($limit)
            ->get()
            ->map(fn ($row): array => [
                'schedule_id' => (int) $row->schedule_id,
                'title' => (string) $row->title,
                'start' => $row->start ? Carbon::parse($row->start)->toDateTimeString() : null,
                'attendees' => (int) $row->attendees,
                'known' => (int) $row->known,
                'clicked' => (int) $row->clicked,
                'avg_minutes' => $row->avg_dur ? (int) round($row->avg_dur / 60) : null,
            ]);
    }
}
