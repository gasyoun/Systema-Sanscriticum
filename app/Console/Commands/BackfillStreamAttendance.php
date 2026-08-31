<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\WebinarAttendance;
use App\Services\Zoom\AttendanceRecorder;
use App\Services\Zoom\ZoomService;
use App\Support\InsertOnlyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * H3761, волна 3 — досбор посещаемости потоков курса из Zoom Reports API.
 *
 * Отличия от `zoom:backfill-attendance` (H3085), который остаётся для случая
 * «курс без zoom_meeting_id, часть занятий вообще не заведена»:
 *
 *  1. **Только вставки.** Команда не обновляет и не удаляет ничего — ни
 *     `webinar_attendances`, ни `schedules`, ни `courses`. Запрет проверяется
 *     во время выполнения ([InsertOnlyGuard]), а не только на ревью: повторный
 *     прогон обязан быть no-op. H3085 при этом делает `updateOrCreate` и
 *     проставляет `schedules.zoom_meeting_id` — в боевых данных волны 3 это
 *     запрещено.
 *  2. **Занятия не изобретаются.** Запуск Zoom привязывается только к уже
 *     существующему `Schedule` на ТУ ЖЕ календарную дату. Заводить занятия
 *     задним числом нельзя: `schedules` кормит помесячное признание зарплаты
 *     преподавателя, и атрибуция запуска общей «личной комнаты» к конкретному
 *     курсу — решение человека, а не эвристики.
 *  3. **Слепой период называется вслух.** Отчёт всегда печатает обе стороны
 *     расхождения: занятия без источника в Zoom и запуски Zoom без занятия в
 *     системе. Ноль в посещаемости и «данных не сохранилось» — разные вещи.
 *  4. **Порог участников.** Общая recurring-комната хранит и служебные запуски
 *     (host заглянул на минуту). `--min-participants` отсекает их, чтобы они
 *     не считались занятием.
 *
 * Диагноз, ради которого команда написана:
 * docs/DIAGNOSIS_SYSTEMA_STREAM_ATTENDANCE_31-08-2026.md
 */
class BackfillStreamAttendance extends Command
{
    protected $signature = 'attendance:backfill-streams
        {--course=* : ID курса; можно повторять. Без опции — все курсы с разрешимым meeting_id}
        {--since= : Не рассматривать запуски раньше этой даты (YYYY-MM-DD). Обязателен}
        {--until= : Не рассматривать запуски позже этой даты (YYYY-MM-DD), по умолчанию сегодня}
        {--min-participants=2 : Запуск с меньшим числом участников считается служебным, а не занятием}
        {--apply : Выполнить вставки (без опции — сухой прогон)}';

    protected $description = 'Досбор webinar_attendances потоков из Zoom Reports API — только вставки, без правки существующих строк';

    public function handle(ZoomService $zoom, AttendanceRecorder $recorder): int
    {
        if (! $zoom->isConfigured()) {
            $this->error('Zoom не сконфигурирован (ZOOM_ACCOUNT_ID/CLIENT_ID/CLIENT_SECRET).');

            return self::FAILURE;
        }

        $since = $this->option('since');
        if (! $since) {
            $this->error('--since обязателен: общая комната Zoom хранит и чужие активности, дата отсекает их.');

            return self::FAILURE;
        }

        $sinceDate = Carbon::parse($since)->startOfDay();
        $untilDate = $this->option('until') ? Carbon::parse($this->option('until'))->endOfDay() : now()->endOfDay();
        $minParticipants = max(1, (int) $this->option('min-participants'));

        $courses = $this->targetCourses();
        if ($courses->isEmpty()) {
            $this->warn('Не найдено ни одного курса с разрешимым zoom_meeting_id — досбирать нечего.');

            return self::SUCCESS;
        }

        $planRows = [];
        $blindLessons = [];
        $blindZoomRuns = [];
        $totalInsertable = 0;

        foreach ($courses as $course) {
            [$meetingId, $source] = $this->resolveMeetingId($course);
            if ($meetingId === null) {
                continue;
            }

            $instances = collect($zoom->pastMeetingInstances($meetingId))
                ->filter(fn (array $i) => ! empty($i['uuid']) && ! empty($i['start_time']))
                ->filter(function (array $i) use ($sinceDate, $untilDate) {
                    return Carbon::parse($i['start_time'])->betweenIncluded($sinceDate, $untilDate);
                })
                ->values();

            // Дата занятия — локальная календарная дата запуска: Zoom отдаёт UTC,
            // а `schedules.start` хранится в тайм-зоне приложения.
            $byDate = $instances->groupBy(
                fn (array $i) => Carbon::parse($i['start_time'])->setTimezone(config('app.timezone'))->toDateString()
            );

            $schedulesByDate = Schedule::where('course_id', $course->id)
                ->whereNotNull('start')
                ->whereBetween('start', [$sinceDate, $untilDate])
                ->get()
                ->groupBy(fn (Schedule $s) => $s->start->toDateString());

            foreach ($byDate as $date => $dayInstances) {
                /** @var Schedule|null $schedule */
                $schedule = $schedulesByDate->get($date)?->first();

                $participants = [];
                foreach ($dayInstances as $instance) {
                    foreach ($zoom->meetingParticipants((string) $instance['uuid']) as $p) {
                        $participants[] = $p;
                    }
                }

                if (count($participants) < $minParticipants) {
                    continue; // служебный запуск, не занятие
                }

                if ($schedule === null) {
                    $blindZoomRuns[] = [$course->id, $date, count($dayInstances), count($participants)];

                    continue;
                }

                $missing = $this->countMissing($schedule->id, $participants, $recorder);
                $totalInsertable += $missing;

                $planRows[] = [
                    $course->id,
                    $date,
                    $schedule->id,
                    count($dayInstances),
                    count($participants),
                    $missing,
                ];
            }

            foreach ($schedulesByDate as $date => $group) {
                /** @var Schedule $schedule */
                $schedule = $group->first();
                $hasZoom = $byDate->has($date);
                $hasRows = WebinarAttendance::where('schedule_id', $schedule->id)->exists();

                if (! $hasZoom && ! $hasRows) {
                    $blindLessons[] = [$course->id, $date, $schedule->id, $source];
                }
            }
        }

        $this->renderReport($planRows, $blindLessons, $blindZoomRuns, $totalInsertable);

        if (! $this->option('apply')) {
            $this->comment('Сухой прогон — ничего не записано. Повторите с --apply.');

            return self::SUCCESS;
        }

        $inserted = $this->apply($planRows, $zoom, $recorder, $sinceDate, $untilDate);

        $this->info("Вставлено строк посещаемости: {$inserted}.");
        $this->comment('Ни одна существующая строка не изменена и не удалена (InsertOnlyGuard).');

        return self::SUCCESS;
    }

    /** @return Collection<int, Course> */
    private function targetCourses(): Collection
    {
        $ids = array_filter(array_map('intval', (array) $this->option('course')));

        if ($ids !== []) {
            return Course::whereIn('id', $ids)->get();
        }

        return Course::query()
            ->where(function ($q) {
                $q->whereNotNull('zoom_meeting_id')->where('zoom_meeting_id', '<>', '')
                    ->orWhere('zoom_link', 'like', '%zoom.us/j/%');
            })
            ->get();
    }

    /**
     * Разрешение meeting_id: поле курса → общая ссылка курса → ссылка любого
     * его занятия. Волна 3 обязана проверить именно эту цепочку — курс 332
     * («1 поток») не хранит meeting_id ни в одном из трёх мест.
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveMeetingId(Course $course): array
    {
        if (! empty($course->zoom_meeting_id)) {
            return [(string) $course->zoom_meeting_id, 'courses.zoom_meeting_id'];
        }

        if (preg_match('~/j/(\d+)~', (string) $course->zoom_link, $m)) {
            return [$m[1], 'courses.zoom_link'];
        }

        $sample = Schedule::where('course_id', $course->id)
            ->whereNotNull('link')
            ->where('link', 'like', '%zoom.us%')
            ->first();

        if ($sample && preg_match('~/j/(\d+)~', (string) $sample->link, $m)) {
            return [$m[1], 'schedules.link'];
        }

        return [null, 'не разрешён'];
    }

    /** Сколько строк добавилось бы: ключ (schedule_id + participant_uuid) ещё не занят. */
    private function countMissing(int $scheduleId, array $participants, AttendanceRecorder $recorder): int
    {
        $existing = WebinarAttendance::where('schedule_id', $scheduleId)
            ->pluck('zoom_participant_uuid')
            ->flip();

        $seen = [];
        foreach ($participants as $p) {
            $key = $recorder->participantKeyFor($p);
            if (trim($key, '|') === '' || $existing->has($key) || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
        }

        return count($seen);
    }

    private function renderReport(array $planRows, array $blindLessons, array $blindZoomRuns, int $totalInsertable): void
    {
        $this->info('План досбора (только вставки):');
        $this->table(
            ['Курс', 'Дата', 'Schedule', 'Запусков Zoom', 'Участников', 'К вставке'],
            $planRows ?: [['—', '—', '—', '—', '—', 0]]
        );

        $this->newLine();
        $this->warn('Слепые занятия — в системе есть, в Zoom источника нет (посещаемость невосстановима):');
        $this->table(
            ['Курс', 'Дата', 'Schedule', 'Источник meeting_id'],
            $blindLessons ?: [['—', '—', '—', '—']]
        );

        $this->newLine();
        $this->warn('Запуски Zoom без занятия в системе — привязать нельзя, занятие заводит человек:');
        $this->table(
            ['Курс', 'Дата', 'Запусков', 'Участников'],
            $blindZoomRuns ?: [['—', '—', '—', '—']]
        );

        $this->newLine();
        $this->info("Итого к вставке: {$totalInsertable} строк.");
    }

    private function apply(
        array $planRows,
        ZoomService $zoom,
        AttendanceRecorder $recorder,
        Carbon $sinceDate,
        Carbon $untilDate
    ): int {
        $inserted = 0;

        InsertOnlyGuard::around(function () use ($planRows, $zoom, $recorder, $sinceDate, $untilDate, &$inserted): void {
            DB::transaction(function () use ($planRows, $zoom, $recorder, $sinceDate, $untilDate, &$inserted): void {
                foreach ($planRows as [$courseId, $date, $scheduleId, , , $missing]) {
                    if ($missing === 0) {
                        continue;
                    }

                    /** @var Course $course */
                    $course = Course::find($courseId);
                    [$meetingId] = $this->resolveMeetingId($course);
                    if ($meetingId === null) {
                        continue;
                    }

                    foreach ($zoom->pastMeetingInstances($meetingId) as $instance) {
                        $uuid = (string) ($instance['uuid'] ?? '');
                        $startTime = (string) ($instance['start_time'] ?? '');
                        if ($uuid === '' || $startTime === '') {
                            continue;
                        }

                        $at = Carbon::parse($startTime);
                        if (! $at->betweenIncluded($sinceDate, $untilDate)) {
                            continue;
                        }
                        if ($at->setTimezone(config('app.timezone'))->toDateString() !== $date) {
                            continue;
                        }

                        foreach ($zoom->meetingParticipants($uuid) as $p) {
                            if ($recorder->insertReportRowIfMissing((int) $scheduleId, $p)) {
                                $inserted++;
                            }
                        }
                    }
                }
            });
        });

        return $inserted;
    }
}
