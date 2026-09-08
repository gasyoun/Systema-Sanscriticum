<?php

declare(strict_types=1);

namespace App\Services\Schedule;

use App\Models\Course;
use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use App\Models\WebinarAttendance;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * H4392 (MG 08-09-2026): еженедельный пост «Кто на чём закончил» в чат
 * «Институт». По каждой идущей группе — весь ростер с последним РЕАЛЬНО
 * посещённым занятием каждого студента и пропуски 2+ подряд.
 *
 * Решения MG 08-09-2026 (чат):
 *  - «на чём закончил» = факт присутствия (WebinarAttendance); клик по ссылке
 *    (ScheduleJoinClick) — факт weaker: помечается отдельно, посещением НЕ
 *    считается (ClassAttendanceService тоже различает present/clicked);
 *  - «пропустил» = занятие без attendance И без клика (полная неявка);
 *  - группы = идущие: ≥1 прошедшего И ≥1 будущего занятия;
 *  - ростер = все студенты группы (User::groups).
 *
 * Нумерация занятий зеркалит FullSchedulePost::compose (обзорное не в счёт),
 * даты — тот же формат «1 сентября 2026 (вторник), 19:30».
 */
final class WeeklyFinishReport
{
    /**
     * Отчёт по всем идущим группам активных видимых курсов.
     *
     * @return list<array{course: Course, group: Group, pastCount: int, futureCount: int, students: list<array{user: User, last: ?array{label: string, date: string}, clicked: ?array{label: string, date: string}, missedStreak: int}>}
     */
    public static function build(): array
    {
        $courses = Course::query()
            ->where('is_active', true)
            ->where('is_visible', true)
            ->whereHas('groups')
            ->with('groups:id,name')
            ->orderBy('title')
            ->get();

        $report = [];

        foreach ($courses as $course) {
            foreach ($course->groups as $group) {
                $sessions = self::groupSessions($group);
                $past = $sessions->filter(fn (Schedule $s): bool => self::isPast($s))->values();
                $futureCount = $sessions->filter(
                    fn (Schedule $s): bool => $s->start !== null && $s->start->isFuture(),
                )->count();

                if ($past->isEmpty() || $futureCount === 0) {
                    continue; // не идущая группа: ещё не стартовала или закончилась
                }

                $report[] = [
                    'course' => $course,
                    'group' => $group,
                    // «Прошло N» — нумерованные занятия (обзорное не в счёт,
                    // зеркально FullSchedulePost «Прошло занятий»).
                    'pastCount' => $past->reject(fn (Schedule $s): bool => (bool) $s->is_overview)->count(),
                    'futureCount' => $futureCount,
                    'students' => self::studentRows($group, $past),
                ];
            }
        }

        // Детерминированный порядок: курс по названию, группа по названию.
        usort($report, fn (array $a, array $b): int => [$a['course']->title, $a['group']->name] <=> [$b['course']->title, $b['group']->name]);

        return $report;
    }

    /**
     * Telegram HTML поста (может быть несколько сообщений — лимит 4096).
     *
     * @param  list<array{course: Course, group: Group, pastCount: int, futureCount: int, students: list<array{user: User, last: ?array{label: string, date: string}, clicked: ?array{label: string, date: string}, missedStreak: int}>}  $report
     * @return list<string> — чанки, каждый <= self::MAX_TELEGRAM_LENGTH
     */
    public static function telegramChunks(array $report, ?Carbon $weekStart = null): array
    {
        if ($report === []) {
            return [];
        }

        $week = ($weekStart ?? now())->format('d.m.Y');

        // Плоский список сегментов: шапка, на группу — заголовок + строки
        // студентов. Упаковка по сегментам: даже гигантская группа не ломает
        // лимит (одиночный сегмент-студент лимита не достигает физически).
        $segments = ['<b>Кто на чём закончил — неделя '.$week.'</b>'];

        foreach ($report as $row) {
            $segments[] = '<b>'.self::esc($row['course']->title).' — '.self::esc($row['group']->name).'</b>'
                .' (прошло '.$row['pastCount'].' · впереди '.$row['futureCount'].')';
            foreach ($row['students'] as $s) {
                $segments[] = self::esc(self::studentLine($s));
            }
        }

        // Пакуем сегменты в сообщения с запасом под лимит 4096.
        $chunks = [];
        $current = '';
        foreach ($segments as $segment) {
            $candidate = $current === '' ? $segment : $current."\n".$segment;
            if (mb_strlen($candidate) > self::MAX_TELEGRAM_LENGTH && $current !== '') {
                $chunks[] = $current;
                $current = $segment;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private const MAX_TELEGRAM_LENGTH = 3500;

    /**
     * Строка студента: «Иванов Анна — 9-е занятие, 1 сентября 2026 (вторник), 19:30
     * ⚠️ пропустил 2 подряд». Последний факт — только WebinarAttendance; клик —
     * пометка weaker-факта; ничего — «не был ни разу».
     */
    private static function studentLine(array $s): string
    {
        $name = trim((string) $s['user']->name);
        if ($name === '') {
            $name = (string) $s['user']->email;
        }

        $line = $name;

        $last = $s['last'];
        if ($last !== null) {
            $line .= ' — '.$last['label'].', '.$last['date'];
        } else {
            $line .= ' — не был ни разу';
        }

        if ($last === null && isset($s['clicked'])) {
            $line .= ' (кликал: '.$s['clicked']['label'].', '.$s['clicked']['date'].')';
        }

        if ($s['missedStreak'] >= 2) {
            $line .= ' ⚠️ пропустил '.$s['missedStreak'].' подряд';
        }

        return $line;
    }

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Занятия группы: привязанные к ней напрямую либо к её курсам (group_id
     * пуст) — зеркально ClassAttendanceService::forGroup.
     *
     * @return Collection<int, Schedule>
     */
    private static function groupSessions(Group $group)
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

    /**
     * Строки студентов группы: последний факт присутствия + серия полных
     * неявок (без attendance и без клика) от новейшего прошедшего назад.
     *
     * @param  Collection<int, Schedule>  $past  прошедшие, по возрастанию start
     * @return list<array{user: User, last: ?array{label: string, date: string}, clicked: ?array{label: string, date: string}, missedStreak: int}>
     */
    private static function studentRows(Group $group, $past): array
    {
        $scheduleIds = $past->pluck('id');

        $attended = WebinarAttendance::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->whereNotNull('user_id')
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('schedule_id')->unique()->values());

        $clicked = ScheduleJoinClick::query()
            ->whereIn('schedule_id', $scheduleIds)
            ->get()
            ->groupBy('user_id')
            ->map(fn ($rows) => $rows->pluck('schedule_id')->unique()->values());

        $labels = self::scheduleLabels($past);

        $rows = [];
        foreach ($group->users()->orderBy('name')->get() as $user) {
            // Последний факт присутствия: новейшее занятие с attendance.
            $lastScheduleId = $past->reverse()
                ->first(fn (Schedule $s): bool => $attended->get($user->id, collect())->contains($s->id))?->id;
            $last = $lastScheduleId !== null ? $labels[$lastScheduleId] : null;

            // Weaker-факт: кликал, но ни разу не отметился.
            $clickedScheduleId = $lastScheduleId === null
                ? $past->reverse()->first(fn (Schedule $s): bool => $clicked->get($user->id, collect())->contains($s->id))?->id
                : null;
            $clickedLast = $clickedScheduleId !== null ? $labels[$clickedScheduleId] : null;

            // Серия полных неявок от новейшего прошедшего занятия назад.
            $missedStreak = 0;
            foreach ($past->reverse() as $schedule) {
                $hadFact = $attended->get($user->id, collect())->contains($schedule->id)
                    || $clicked->get($user->id, collect())->contains($schedule->id);
                if ($hadFact) {
                    break;
                }
                $missedStreak++;
            }

            $rows[] = [
                'user' => $user,
                'last' => $last,
                'clicked' => $clickedLast,
                'missedStreak' => $missedStreak,
            ];
        }

        return $rows;
    }

    /**
     * Метки занятий: «N-е занятие» (обзорное не в счёт — зеркально
     * FullSchedulePost) + формат даты тот же.
     *
     * @param  Collection<int, Schedule>  $past
     * @return array<int, array{label: string, date: string}> по schedule_id
     */
    private static function scheduleLabels($past): array
    {
        $lessonNumber = 0;
        $labels = [];

        foreach ($past as $schedule) {
            if ((bool) $schedule->is_overview) {
                $labels[$schedule->id] = ['label' => 'Обзорное занятие', 'date' => self::formatDate($schedule->start)];
            } else {
                $lessonNumber++;
                $labels[$schedule->id] = ['label' => $lessonNumber.'-е занятие', 'date' => self::formatDate($schedule->start)];
            }
        }

        return $labels;
    }

    private static function formatDate(Carbon $start): string
    {
        return FullSchedulePost::formatDate($start);
    }

    /** Эффективный конец занятия (end ?? start + 2ч) уже позади — H4387. */
    private static function isPast(Schedule $s): bool
    {
        return $s->start !== null
            && ($s->end ?? $s->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS))->isPast();
    }
}
