<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseBlock;
use App\Models\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Дайджест «какие курсы ОРС идут в этом месяце» для ежемесячного поста в ВК/ТГ.
 * Источник — активные+видимые курсы с блоком, пересекающим текущий месяц (или
 * помеченным is_current), плюс расписание занятий этого месяца из Schedule.
 *
 * Организация в копирайте — «Общество ревнителей санскрита» (ОРС).
 */
class MonthlyScheduleDigest
{
    public const HEADING = '📅 Обращаем внимание: в этом месяце в Обществе ревнителей санскрита (ОРС) идут курсы';

    /**
     * @return list<array{title:string, teacher:?string, schedule:?string, url:?string}>
     */
    public function courses(): array
    {
        $start = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();

        $courses = Course::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->with(['teacher:id,name', 'blocks'])
            ->orderBy('title')
            ->get()
            ->filter(fn (Course $c) => $this->runsThisMonth($c, $start, $end))
            ->values();

        if ($courses->isEmpty()) {
            return [];
        }

        $scheduleByCourse = $this->scheduleByCourse($courses->pluck('id')->all(), $start, $end);

        return $courses->map(fn (Course $c) => [
            'title' => (string) $c->title,
            'teacher' => $c->teacher?->name,
            'schedule' => $scheduleByCourse[$c->id] ?? null,
            'url' => $this->courseUrl($c),
        ])->all();
    }

    public function isEmpty(): bool
    {
        return $this->courses() === [];
    }

    /** Текст для Telegram (HTML). */
    public function textTelegram(): string
    {
        $lines = ['<b>'.self::HEADING.'</b>', ''];

        foreach ($this->courses() as $c) {
            $title = '• <b>'.e($c['title']).'</b>';
            if ($c['teacher']) {
                $title .= ' — '.e($c['teacher']);
            }
            $lines[] = $title;
            if ($c['schedule']) {
                $lines[] = '  🗓 '.e($c['schedule']).' (МСК)';
            }
            if ($c['url']) {
                $lines[] = '  🔗 '.$c['url'];
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    /** Текст для ВК (плоский, ссылки как есть). */
    public function textVk(): string
    {
        $lines = [self::HEADING, ''];

        foreach ($this->courses() as $c) {
            $title = '• '.$c['title'];
            if ($c['teacher']) {
                $title .= ' — '.$c['teacher'];
            }
            $lines[] = $title;
            if ($c['schedule']) {
                $lines[] = '  🗓 '.$c['schedule'].' (МСК)';
            }
            if ($c['url']) {
                $lines[] = '  '.$c['url'];
            }
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    private function runsThisMonth(Course $course, Carbon $start, Carbon $end): bool
    {
        return $course->blocks->contains(function (CourseBlock $b) use ($start, $end) {
            if ($b->is_current) {
                return true;
            }
            if ($b->starts_at === null || $b->ends_at === null) {
                return false;
            }

            // Пересечение [starts_at, ends_at] с [начало месяца, конец месяца].
            return $b->starts_at->lte($end) && $b->ends_at->gte($start);
        });
    }

    /**
     * @param  list<int>  $courseIds
     * @return array<int, string> course_id => «Воскресенье 14:00, Среда 19:00»
     */
    private function scheduleByCourse(array $courseIds, Carbon $start, Carbon $end): array
    {
        if ($courseIds === []) {
            return [];
        }

        $events = Schedule::query()
            ->whereIn('course_id', $courseIds)
            ->whereBetween('start', [$start, $end])
            ->orderBy('start')
            ->get(['course_id', 'start']);

        return $events
            ->groupBy('course_id')
            ->map(function (Collection $rows): string {
                return $rows
                    ->map(fn (Schedule $s) => ucfirst($s->start->locale('ru')->translatedFormat('l')).' '.$s->start->format('H:i'))
                    ->unique()
                    ->implode(', ');
            })
            ->all();
    }

    private function courseUrl(Course $course): ?string
    {
        if (empty($course->slug)) {
            return null;
        }

        return rtrim((string) config('app.url'), '/').route('shop.course.show', $course->slug, false);
    }
}
