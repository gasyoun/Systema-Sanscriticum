<?php

declare(strict_types=1);

namespace App\Services\OpenLessonCards;

use App\Models\Course;
use App\Models\Schedule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Какие занятия идут в бот карточкой. Только строки расписания с галочкой
 * bot_card_enabled — автоотбора нет сознательно (пробное платное, разовые
 * курсы бывают платными; карточка обещает «бесплатно» и раздаёт Zoom).
 */
class OpenLessonOccasions
{
    /** @return Builder<Schedule> */
    public function query(): Builder
    {
        return Schedule::query()
            ->where('bot_card_enabled', true)
            ->with(['course.teacher', 'course.teachers', 'group']);
    }

    /** @return Collection<int, Schedule> */
    public function between(Carbon $from, Carbon $to): Collection
    {
        return $this->query()
            ->whereBetween('start', [$from, $to])
            ->orderBy('start')
            ->orderBy('id')
            ->get();
    }

    /** Занятие с галочкой, которое ещё не закончилось (для deep-link и кнопок). */
    public function find(int $scheduleId): ?Schedule
    {
        return $this->query()
            ->whereKey($scheduleId)
            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS))
            ->first();
    }

    /** Курс занятия: прямой course_id, иначе первый курс группы. */
    public function courseFor(Schedule $schedule): ?Course
    {
        if ($schedule->course !== null) {
            return $schedule->course;
        }

        return $schedule->group?->courses()->with(['teacher', 'teachers'])->orderBy('courses.id')->first();
    }

    /** Ссылка на подключение: Zoom-встреча занятия → link/описание → единая ссылка курса. */
    public function joinUrl(Schedule $schedule): ?string
    {
        foreach ([$schedule->zoom_join_url, $schedule->link, $this->courseFor($schedule)?->zoom_link] as $url) {
            if (is_string($url) && preg_match('~^https?://~i', $url)) {
                return $url;
            }
        }

        return null;
    }
}
