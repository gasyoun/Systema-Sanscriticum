<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Schedule;
use App\Models\WebinarAttendance;
use App\Services\Access\TelegramAdminNotifier;
use App\Services\Zoom\AttendanceRecorder;
use App\Services\Zoom\ZoomService;
use App\Support\InsertOnlyGuard;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * H3761, волна 3 — досбор посещаемости потоков курса из Zoom Reports API.
 *
 * Отличия от `zoom:backfill-attendance` (H3085), который остается для случая
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
 *  3. **Слепой период называется вслух.** Отчет всегда печатает обе стороны
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
        {--since= : Не рассматривать запуски раньше этой даты (YYYY-MM-DD). Обязателен вне --scheduled}
        {--until= : Не рассматривать запуски позже этой даты (YYYY-MM-DD), по умолчанию сегодня}
        {--min-participants=2 : Запуск с меньшим числом участников считается служебным, а не занятием}
        {--meeting-id= : Zoom meeting_id вручную — для курса, у которого его нет ни в карточке, ни в занятиях. Только с одним --course}
        {--slot= : Окно занятия «Dow,HH:MM-HH:MM» по времени приложения, напр. "Wed,12:30-15:30". Обязателен вместе с --create-lessons}
        {--create-lessons : Завести занятие под запуск Zoom, которому в системе занятия нет. Только со --slot; решение об атрибуции принимает человек}
        {--scheduled : Режим планировщика (H8494): окно по умолчанию «вчера..сегодня», вердикт-строка с found/skipped в лог и Telegram}
        {--notify : Отправить вердикт-строку админам в Telegram (H8494), не меняя окно}
        {--apply : Выполнить вставки (без опции — сухой прогон)}';

    protected $description = 'Досбор webinar_attendances потоков из Zoom Reports API — только вставки, без правки существующих строк';

    public function handle(ZoomService $zoom, AttendanceRecorder $recorder): int
    {
        if (! $zoom->isConfigured()) {
            $this->error('Zoom не сконфигурирован (ZOOM_ACCOUNT_ID/CLIENT_ID/CLIENT_SECRET).');

            return self::FAILURE;
        }

        $scheduled = (bool) $this->option('scheduled');
        $notify = (bool) $this->option('notify') || $scheduled;

        // H8494: режим планировщика получает окно «вчера..сегодня» — занятие,
        // закончившееся после вечернего прогона, добирается следующим (машина
        // только вставляет, поэтому повторное покрытие окна не даёт дублей).
        $since = $this->option('since') ?? ($scheduled ? now()->subDay()->toDateString() : null);
        if (! $since) {
            $this->error('--since обязателен: общая комната Zoom хранит и чужие активности, дата отсекает их.');

            return self::FAILURE;
        }

        $sinceDate = Carbon::parse($since)->startOfDay();
        $untilDate = $this->option('until') ? Carbon::parse($this->option('until'))->endOfDay() : now()->endOfDay();
        $minParticipants = max(1, (int) $this->option('min-participants'));

        $slot = null;
        if ($this->option('slot')) {
            $slot = $this->parseSlot((string) $this->option('slot'));
            if ($slot === null) {
                $this->error('--slot должен иметь вид "Wed,12:30-15:30": день недели по-английски, затем окно локального времени.');

                return self::FAILURE;
            }
        }

        // Заведение занятия задним числом меняет помесячное признание зарплаты
        // преподавателя, поэтому оно требует явного окна: без него команда
        // приняла бы за занятие любой запуск общей комнаты.
        if ($this->option('create-lessons') && $slot === null) {
            $this->error('--create-lessons требует --slot: общая комната Zoom используется и для других активностей, без окна занятия их не различить.');

            return self::FAILURE;
        }

        // Ручной id относится к ОДНОМУ курсу: раздать его нескольким значило бы
        // приписать одному потоку занятия другого.
        if ($this->option('meeting-id') && count(array_filter((array) $this->option('course'))) !== 1) {
            $this->error('--meeting-id задается только вместе с ровно одним --course.');

            return self::FAILURE;
        }

        $courses = $this->targetCourses();

        // H8494 — пропуск вслух: занятия завершившегося дня, чей курс не имеет
        // разрешимого zoom_meeting_id нигде. Такие занятия не попадают ни в
        // план вставок, ни в слепые таблицы ниже — без отдельного учёта они
        // пропадали бы молча. Считаются только на полном прогоне (без --course):
        // явный --course — выбор оператора, и сужать вердикт за него нельзя.
        $skippedUnresolved = $this->option('course') === []
            ? $this->skippedUnresolvedLessons($sinceDate, $untilDate)
            : [];

        if ($courses->isEmpty()) {
            $this->warn('Не найдено ни одного курса с разрешимым zoom_meeting_id — досбирать нечего.');
            $this->reportSkippedUnresolved($skippedUnresolved);
            $this->emitVerdict(
                found: 0,
                blindLessons: 0,
                blindZoomRuns: 0,
                unresolvedLessons: count($skippedUnresolved),
                insertedRows: null,
                notify: $notify,
            );

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
                ->filter(fn (array $i) => $this->inSlot(Carbon::parse($i['start_time']), $slot))
                ->values();

            // Дата занятия — локальная календарная дата запуска: Zoom отдает UTC,
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

                if ($schedule === null && ! $this->option('create-lessons')) {
                    $blindZoomRuns[] = [$course->id, $date, count($dayInstances), count($participants)];

                    continue;
                }

                // Без занятия вставлять посещаемость некуда: строка ссылается на
                // schedule_id. С --create-lessons занятие заводится в apply().
                $missing = $schedule === null
                    ? count($this->distinctKeys($participants, $recorder))
                    : $this->countMissing($schedule->id, $participants, $recorder);
                $totalInsertable += $missing;

                $planRows[] = [
                    $course->id,
                    $date,
                    $schedule?->id,
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
        $this->reportSkippedUnresolved($skippedUnresolved);

        if (! $this->option('apply')) {
            $this->comment('Сухой прогон — ничего не записано. Повторите с --apply.');
            $this->emitVerdict(
                found: $totalInsertable,
                blindLessons: count($blindLessons),
                blindZoomRuns: count($blindZoomRuns),
                unresolvedLessons: count($skippedUnresolved),
                insertedRows: null,
                notify: $notify,
            );

            return self::SUCCESS;
        }

        $result = $this->apply($planRows, $zoom, $recorder, $sinceDate, $untilDate, $slot);

        $this->info("Заведено занятий: {$result['created']}. Вставлено строк посещаемости: {$result['inserted']}.");
        $this->comment('Ни одна существующая строка не изменена и не удалена (InsertOnlyGuard).');
        $this->emitVerdict(
            found: $result['inserted'],
            blindLessons: count($blindLessons),
            blindZoomRuns: count($blindZoomRuns),
            unresolvedLessons: count($skippedUnresolved),
            insertedRows: $result['inserted'],
            notify: $notify,
        );

        return self::SUCCESS;
    }

    /**
     * H8494 — занятия окна без разрешимого zoom_meeting_id, пропущенные вслух.
     *
     * Ровно та часть слепого периода, которую таблицы ниже не видят: слепое
     * занятие (источник Zoom не сохранил) и слепой запуск (в системе нет
     * занятия) считаются по курсам с разрешимым id, а курс БЕЗ id вообще
     * раньше не упоминался нигде. Атрибуция его комнаты и точечный прогон
     * zoom:backfill-attendance (H3085) — решение человека, машина только
     * называет такие занятия.
     *
     * @return list<array{0: int, 1: string, 2: int}> [courseId, date, scheduleId]
     */
    private function skippedUnresolvedLessons(Carbon $sinceDate, Carbon $untilDate): array
    {
        if ($this->option('meeting-id')) {
            // Ручной id валиден только для одного курса; в полном прогоне его
            // нет, поэтому с ним подсчёт пропусков не имеет смысла.
            return [];
        }

        $rows = Schedule::query()
            ->whereNotNull('start')
            ->whereNotNull('course_id')
            ->whereBetween('start', [$sinceDate, $untilDate])
            ->orderBy('id')
            ->get();

        $resolvedCourses = [];

        return $rows
            ->map(function (Schedule $schedule) use (&$resolvedCourses): ?array {
                $courseId = (int) $schedule->course_id;
                if (! array_key_exists($courseId, $resolvedCourses)) {
                    /** @var Course|null $course */
                    $course = Course::find($courseId);
                    $resolvedCourses[$courseId] = $course === null ? null : $this->resolveMeetingId($course)[0];
                }

                if ($resolvedCourses[$courseId] !== null) {
                    return null;
                }

                return [$courseId, $schedule->start->toDateString(), $schedule->id];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** Громкая таблица пропущенных занятий — пустой список не печатается. */
    private function reportSkippedUnresolved(array $skippedUnresolved): void
    {
        if ($skippedUnresolved === []) {
            return;
        }

        $this->newLine();
        $this->warn('Занятия без разрешимого zoom_meeting_id — пропуск вслух (H8494), посещаемость не собирается:');
        $this->table(
            ['Курс', 'Дата', 'Schedule'],
            array_map(fn (array $r) => [$r[0], $r[1], $r[2]], $skippedUnresolved)
        );
    }

    /**
     * Вердикт-строка H8494: одна строка с found/skipped в консоль и в лог
     * всегда, в Telegram — по --notify/--scheduled (fail-open: сбой TG не
     * роняет прогон). В строке нет имён студентов — только счётчики и окно.
     */
    private function emitVerdict(
        int $found,
        int $blindLessons,
        int $blindZoomRuns,
        int $unresolvedLessons,
        ?int $insertedRows,
        bool $notify,
    ): void {
        $skipped = $blindLessons + $blindZoomRuns + $unresolvedLessons;
        $verdict = sprintf(
            'Вердикт автосбора (H8494): found=%d, skipped=%d (слепые занятия %d, запуски Zoom без занятия %d, занятия без meeting_id %d).',
            $found,
            $skipped,
            $blindLessons,
            $blindZoomRuns,
            $unresolvedLessons,
        );

        $this->line($verdict);

        Log::info('attendance.auto_capture_verdict', [
            'found' => $found,
            'skipped' => $skipped,
            'blind_lessons' => $blindLessons,
            'blind_zoom_runs' => $blindZoomRuns,
            'unresolved_lessons' => $unresolvedLessons,
            'inserted_rows' => $insertedRows,
        ]);

        if (! $notify) {
            return;
        }

        try {
            $delivered = app(TelegramAdminNotifier::class)->notifyAdmins($verdict);

            if ($delivered === []) {
                Log::warning('attendance.auto_capture_verdict_undelivered', ['reason' => 'tg not configured or send failed']);
                $this->comment('TG-вердикт не доставлен (нет настроенных получателей или сбой отправки).');
            } else {
                Log::info('attendance.auto_capture_verdict_delivered', ['recipients' => count($delivered)]);
                $this->comment('TG-вердикт доставлен получателям: '.count($delivered).'.');
            }
        } catch (Throwable $e) {
            // Soft-alert по контракту: сбой доставки не имеет права ронять сбор.
            Log::warning('attendance.auto_capture_verdict_send_failed', [
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
        }
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
     * («1 поток») не хранит meeting_id ни в одном из трех мест.
     *
     * @return array{0: ?string, 1: string}
     */
    private function resolveMeetingId(Course $course): array
    {
        // Переданный вручную id перекрывает цепочку — это единственный способ
        // добраться до потока, который шел до появления раздела «Занятия»:
        // у такого курса ссылки нет нигде, резолвить не из чего.
        if ($this->option('meeting-id')) {
            return [(string) $this->option('meeting-id'), 'передан через --meeting-id'];
        }

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

        return [null, 'не разрешен'];
    }

    /** Сколько строк добавилось бы: ключ (schedule_id + participant_uuid) еще не занят. */
    private function countMissing(int $scheduleId, array $participants, AttendanceRecorder $recorder): int
    {
        $existing = WebinarAttendance::where('schedule_id', $scheduleId)
            ->pluck('zoom_participant_uuid')
            ->flip();

        $seen = $this->distinctKeys($participants, $recorder);

        return count(array_diff_key($seen, $existing->all()));
    }

    /**
     * Уникальные ключи участия среди запусков дня — несколько подключений
     * одного человека за одно занятие дают одну строку.
     *
     * @return array<string, true>
     */
    private function distinctKeys(array $participants, AttendanceRecorder $recorder): array
    {
        $seen = [];
        foreach ($participants as $p) {
            $key = $recorder->participantKeyFor($p);
            if (trim($key, '|') === '') {
                continue;
            }
            $seen[$key] = true;
        }

        return $seen;
    }

    /**
     * Разбор окна занятия «Wed,12:30-15:30».
     *
     * @return array{dow: int, from: int, to: int}|null минуты от полуночи
     */
    private function parseSlot(string $raw): ?array
    {
        if (! preg_match('~^\s*([A-Za-z]{3}),\s*(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})\s*$~', $raw, $m)) {
            return null;
        }

        $days = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 0];
        $dow = $days[strtolower($m[1])] ?? null;
        if ($dow === null) {
            return null;
        }

        $from = ((int) $m[2]) * 60 + (int) $m[3];
        $to = ((int) $m[4]) * 60 + (int) $m[5];

        return $to > $from ? ['dow' => $dow, 'from' => $from, 'to' => $to] : null;
    }

    /** Попадает ли запуск Zoom в окно занятия (по локальному времени приложения). */
    private function inSlot(Carbon $utc, ?array $slot): bool
    {
        if ($slot === null) {
            return true;
        }

        $local = $utc->copy()->setTimezone(config('app.timezone'));
        if ((int) $local->dayOfWeek !== $slot['dow']) {
            return false;
        }

        $minutes = $local->hour * 60 + $local->minute;

        return $minutes >= $slot['from'] && $minutes <= $slot['to'];
    }

    private function renderReport(array $planRows, array $blindLessons, array $blindZoomRuns, int $totalInsertable): void
    {
        $this->info('План досбора (только вставки):');
        $this->table(
            ['Курс', 'Дата', 'Schedule', 'Запусков Zoom', 'Участников', 'К вставке'],
            array_map(
                fn (array $r) => [$r[0], $r[1], $r[2] ?? 'завести занятие', $r[3], $r[4], $r[5]],
                $planRows
            ) ?: [['—', '—', '—', '—', '—', 0]]
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

    /**
     * Завести занятие под подтвержденный запуск Zoom. Только со --slot: время
     * занятия берется из середины окна, чтобы новая строка попадала в тот же
     * слот, а не в момент, когда ведущий нажал «начать».
     *
     * Заголовок помечен бэкфилом: строка, заведенная задним числом, должна быть
     * отличима от расписания, которое человек вел сам.
     */
    private function createLesson(Course $course, string $date, string $meetingId): ?int
    {
        $slot = $this->parseSlot((string) $this->option('slot'));
        if ($slot === null) {
            return null;
        }

        $start = Carbon::parse($date)->startOfDay()->addMinutes($slot['from']);
        $end = Carbon::parse($date)->startOfDay()->addMinutes($slot['to']);

        $anchor = Schedule::where('course_id', $course->id)->whereNotNull('start')->first();

        $schedule = Schedule::create([
            'title' => $course->title.' — занятие '.Carbon::parse($date)->format('d.m.y').' (бэкфил H3761)',
            'link' => $course->zoom_link ?: $anchor?->link,
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
            'group_id' => $anchor?->group_id,
            'course_id' => $course->id,
            'zoom_meeting_id' => $meetingId,
        ]);

        return (int) $schedule->id;
    }

    /** @return array{inserted: int, created: int} */
    private function apply(
        array $planRows,
        ZoomService $zoom,
        AttendanceRecorder $recorder,
        Carbon $sinceDate,
        Carbon $untilDate,
        ?array $slot
    ): array {
        $inserted = 0;
        $created = 0;

        InsertOnlyGuard::around(function () use ($planRows, $zoom, $recorder, $sinceDate, $untilDate, $slot, &$inserted, &$created): void {
            DB::transaction(function () use ($planRows, $zoom, $recorder, $sinceDate, $untilDate, $slot, &$inserted, &$created): void {
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

                    if ($scheduleId === null) {
                        $scheduleId = $this->createLesson($course, $date, $meetingId);
                        if ($scheduleId === null) {
                            continue;
                        }
                        $created++;
                    }

                    foreach ($zoom->pastMeetingInstances($meetingId) as $instance) {
                        $uuid = (string) ($instance['uuid'] ?? '');
                        $startTime = (string) ($instance['start_time'] ?? '');
                        if ($uuid === '' || $startTime === '') {
                            continue;
                        }

                        $at = Carbon::parse($startTime);
                        if (! $at->betweenIncluded($sinceDate, $untilDate) || ! $this->inSlot($at, $slot)) {
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

        return ['inserted' => $inserted, 'created' => $created];
    }
}
