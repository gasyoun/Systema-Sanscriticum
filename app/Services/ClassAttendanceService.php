<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Group;
use App\Models\Schedule;
use App\Models\ScheduleJoinClick;
use App\Models\User;
use App\Models\WebinarAttendance;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Сведение посещаемости: «ожидали (ClassRoster) vs пришли (WebinarAttendance) /
 * перешли по ссылке (ScheduleJoinClick)».
 *
 * Статусы по студенту:
 *  - present — Zoom опознал (email-матч): был, есть минуты;
 *  - clicked — перешёл по трекинг-ссылке, но Zoom не опознал (вероятно был);
 *  - absent  — ни того, ни другого.
 */
class ClassAttendanceService
{
    /**
     * Ростер одного занятия со статусами + неопознанные гости + сводка.
     *
     * @return array{roster: Collection, guests: Collection, summary: array<string,int>}
     */
    public function forSchedule(Schedule $schedule): array
    {
        $query = ClassRoster::query($schedule);
        $expected = $query ? $query->orderBy('name')->get() : collect();

        $attendances = $schedule->attendances()->get();
        $byUser = $attendances->whereNotNull('user_id')->groupBy('user_id');
        $clicks = $schedule->joinClicks()->get()->keyBy('user_id');

        $roster = $expected->map(function (User $u) use ($byUser, $clicks): array {
            $att = $byUser->get($u->id);
            $click = $clicks->get($u->id);

            return $this->row($u, $att, $click);
        })->values();

        $guests = $attendances->whereNull('user_id')->values();

        return [
            'roster' => $roster,
            'guests' => $guests,
            'summary' => [
                'expected' => $roster->count(),
                'present' => $roster->where('status', 'present')->count(),
                'clicked' => $roster->where('status', 'clicked')->count(),
                'absent' => $roster->where('status', 'absent')->count(),
                'guests' => $guests->count(),
            ],
        ];
    }

    /**
     * История посещений студента (последние занятия его групп/курсов).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forStudent(User $user, int $limit = 20): Collection
    {
        $groupIds = $user->groups()->pluck('groups.id');

        if ($groupIds->isEmpty()) {
            return collect();
        }

        $schedules = Schedule::query()
            ->whereNotNull('start')
            ->where('start', '<=', now())
            ->where(function ($q) use ($groupIds): void {
                $q->whereIn('group_id', $groupIds)
                    ->orWhereHas('course.groups', fn ($g) => $g->whereIn('groups.id', $groupIds));
            })
            ->with(['group', 'course'])
            ->orderByDesc('start')
            ->limit($limit)
            ->get();

        $scheduleIds = $schedules->pluck('id');
        $att = WebinarAttendance::whereIn('schedule_id', $scheduleIds)
            ->where('user_id', $user->id)->get()->groupBy('schedule_id');
        $clicks = ScheduleJoinClick::whereIn('schedule_id', $scheduleIds)
            ->where('user_id', $user->id)->get()->keyBy('schedule_id');

        return $schedules->map(function (Schedule $s) use ($user, $att, $clicks): array {
            $row = $this->row($user, $att->get($s->id), $clicks->get($s->id));
            $row['schedule'] = $s;

            return $row;
        });
    }

    /**
     * Матрица посещаемости группы: студенты × занятия за период.
     *
     * @return array{schedules: Collection, students: Collection, status: array<int, array<int, string>>}
     */
    public function forGroup(Group $group, CarbonInterface $from, CarbonInterface $to): array
    {
        $schedules = Schedule::query()
            ->where('group_id', $group->id)
            ->whereBetween('start', [$from, $to])
            ->orderBy('start')
            ->get();

        $students = $group->users()->orderBy('name')->get();
        $scheduleIds = $schedules->pluck('id');

        $present = WebinarAttendance::whereIn('schedule_id', $scheduleIds)
            ->whereNotNull('user_id')->get()
            ->groupBy(fn (WebinarAttendance $a) => $a->schedule_id.'-'.$a->user_id);
        $clicks = ScheduleJoinClick::whereIn('schedule_id', $scheduleIds)->get()
            ->groupBy(fn (ScheduleJoinClick $c) => $c->schedule_id.'-'.$c->user_id);

        $status = [];
        foreach ($students as $student) {
            foreach ($schedules as $schedule) {
                $key = $schedule->id.'-'.$student->id;
                $status[$student->id][$schedule->id] = $present->has($key)
                    ? 'present'
                    : ($clicks->has($key) ? 'clicked' : 'absent');
            }
        }

        return ['schedules' => $schedules, 'students' => $students, 'status' => $status];
    }

    /**
     * Один статус-ряд по студенту. present, если Zoom опознал (есть запись
     * посещаемости с user_id); иначе clicked, если перешёл по ссылке; иначе absent.
     *
     * @param  Collection|null  $att  записи WebinarAttendance этого студента в занятии
     */
    private function row(User $user, ?Collection $att, ?ScheduleJoinClick $click): array
    {
        $present = $att !== null && $att->isNotEmpty();
        $minutes = $present ? (int) round((int) $att->sum('duration_seconds') / 60) : null;
        $clicked = $click !== null;

        return [
            'user' => $user,
            'status' => $present ? 'present' : ($clicked ? 'clicked' : 'absent'),
            'attended' => $present || $clicked,
            'minutes' => $minutes,
            'click_source' => $click?->source,
            'clicked_at' => $click?->first_clicked_at,
        ];
    }
}
