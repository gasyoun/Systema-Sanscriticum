<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Schedule;
use App\Services\Zoom\AttendanceRecorder;
use App\Services\Zoom\ZoomService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * H3085 — бэкфил посещаемости для курса, у которого `courses.zoom_meeting_id`
 * никогда не был выставлен (личная recurring-комната Zoom, переиспользуемая
 * между разными активностями преподавателя), а `schedules` заведён лишь на
 * часть реальных занятий.
 *
 * Источник истины по датам занятий — Zoom Reports API
 * (`past_meetings/{id}/instances`), а не `lessons.lesson_date` (это даты
 * контента, не даты живых встреч). Без `--apply` только показывает план:
 * какая дата уже покрыта существующим `Schedule`, какую предстоит завести,
 * и какие даты в запрошенном диапазоне в Zoom не нашлись вовсе (бэкфил для
 * них невозможен — источник данных не сохранил их).
 */
class BackfillZoomAttendance extends Command
{
    protected $signature = 'zoom:backfill-attendance
        {course : ID курса}
        {--since= : Не рассматривать запуски встречи раньше этой даты (YYYY-MM-DD) — обязателен, чтобы не подмешать чужие активности личной комнаты}
        {--until= : Не рассматривать запуски встречи позже этой даты (YYYY-MM-DD), по умолчанию — сегодня}
        {--apply : Завести недостающие Schedule, проставить zoom_meeting_id и записать посещаемость}';

    protected $description = 'Бэкфил Schedule + webinar_attendances курса из истории Zoom (для курсов без courses.zoom_meeting_id)';

    public function handle(ZoomService $zoom, AttendanceRecorder $recorder): int
    {
        if (! $zoom->isConfigured()) {
            $this->error('Zoom не сконфигурирован (ZOOM_ACCOUNT_ID/CLIENT_ID/CLIENT_SECRET).');

            return self::FAILURE;
        }

        $since = $this->option('since');
        if (! $since) {
            $this->error('--since обязателен: личная комната Zoom используется для других активностей вне этого курса, дата отсекает их.');

            return self::FAILURE;
        }
        $sinceDate = Carbon::parse($since)->startOfDay();
        $untilDate = $this->option('until') ? Carbon::parse($this->option('until'))->endOfDay() : now();

        /** @var Course|null $course */
        $course = Course::find((int) $this->argument('course'));
        if (! $course) {
            $this->error('Курс не найден.');

            return self::FAILURE;
        }

        $meetingId = $course->zoom_meeting_id;
        if (! $meetingId) {
            // Курс сам никогда не хранил meeting_id — берём его с любого уже
            // существующего Schedule этого курса, где ссылка реально зоомная.
            $sample = Schedule::where('course_id', $course->id)
                ->whereNotNull('link')
                ->where('link', 'like', '%zoom.us%')
                ->first();

            if (! $sample || ! preg_match('~/j/(\d+)~', (string) $sample->link, $m)) {
                $this->error('Не удалось определить zoom_meeting_id: ни у курса, ни у его Schedule нет ссылки вида .../j/{id}. Передайте его вручную через courses.zoom_link.');

                return self::FAILURE;
            }
            $meetingId = $m[1];
        }

        $instances = collect($zoom->pastMeetingInstances($meetingId))
            ->filter(fn (array $i) => ! empty($i['uuid']) && ! empty($i['start_time']))
            ->filter(function (array $i) use ($sinceDate, $untilDate) {
                $t = Carbon::parse($i['start_time']);

                return $t->betweenIncluded($sinceDate, $untilDate);
            })
            ->sortBy('start_time')
            ->values();

        if ($instances->isEmpty()) {
            $this->warn('В заданном диапазоне Zoom не вернул ни одного запуска встречи — бэкфилить нечего.');

            return self::SUCCESS;
        }

        // Группировка по календарной дате (UTC) — несколько запусков в одну
        // дату (переподключение хоста) относим к одному занятию.
        $byDate = $instances->groupBy(fn (array $i) => Carbon::parse($i['start_time'])->toDateString());

        $existing = Schedule::where('course_id', $course->id)
            ->whereNotNull('start')
            ->get()
            ->groupBy(fn (Schedule $s) => $s->start->toDateString());

        // Опорное время занятия — если у курса уже есть хоть один Schedule,
        // берём его локальное время (час:минута) как канон; иначе 14:00–16:00
        // (наблюдавшийся паттерн этого курса) как безопасный дефолт с явной пометкой.
        $anchor = Schedule::where('course_id', $course->id)->whereNotNull('start')->first();
        $startTime = $anchor ? $anchor->start->format('H:i:s') : '14:00:00';
        $endTime = $anchor && $anchor->end ? $anchor->end->format('H:i:s') : '16:00:00';
        $groupId = $anchor?->group_id;
        $link = $anchor?->link;

        $this->info(sprintf(
            'Курс %d, meeting_id=%s, диапазон %s..%s, найдено дат: %d.',
            $course->id, $meetingId, $sinceDate->toDateString(), $untilDate->toDateString(), $byDate->count()
        ));

        $plan = [];
        foreach ($byDate as $date => $dayInstances) {
            $scheduleRow = $existing->get($date)?->first();
            $plan[] = [
                'date' => $date,
                'instances' => $dayInstances->pluck('uuid')->all(),
                'schedule_id' => $scheduleRow?->id,
                'action' => $scheduleRow ? 'attach' : 'create',
            ];
        }

        $this->table(
            ['Дата', 'Zoom-запусков', 'Schedule', 'Действие'],
            array_map(fn ($p) => [
                $p['date'],
                count($p['instances']),
                $p['schedule_id'] ?? '—',
                $p['action'] === 'attach' ? 'привязать к #'.$p['schedule_id'] : 'завести новый'.($anchor ? '' : ' (нет опорного Schedule — время 14:00–16:00 по умолчанию)'),
            ], $plan)
        );

        if (! $this->option('apply')) {
            $this->comment('Сухой прогон. Повторите с --apply, чтобы записать.');

            return self::SUCCESS;
        }

        if (! $link) {
            $this->error('Нет ссылки-образца (ни у курса, ни у Schedule) — при --apply не на что ссылаться для новых Schedule. Прервано без записи.');

            return self::FAILURE;
        }

        $participantsSynced = 0;
        DB::transaction(function () use ($plan, $course, $link, $groupId, $startTime, $endTime, $zoom, $recorder, &$participantsSynced): void {
            foreach ($plan as $p) {
                $scheduleId = $p['schedule_id'];

                if ($p['action'] === 'create') {
                    $schedule = Schedule::create([
                        'title' => $course->title.' — занятие '.Carbon::parse($p['date'])->format('d.m.y').' (бэкфил H3085)',
                        'link' => $link,
                        'start' => $p['date'].' '.$startTime,
                        'end' => $p['date'].' '.$endTime,
                        'group_id' => $groupId,
                        'course_id' => $course->id,
                    ]);
                    $scheduleId = $schedule->id;
                }

                if (! preg_match('~/j/(\d+)~', $link, $m)) {
                    continue;
                }
                Schedule::whereKey($scheduleId)->update(['zoom_meeting_id' => $m[1]]);

                foreach ($p['instances'] as $uuid) {
                    foreach ($zoom->meetingParticipants($uuid) as $participant) {
                        $recorder->recordReportRow((int) $scheduleId, $participant);
                        $participantsSynced++;
                    }
                }
            }
        });

        $this->info("Записано участников: {$participantsSynced}.");
        $this->comment('courses.zoom_meeting_id/zoom_link НЕ трогаются автоматически — решение включать курс в живой zoom:sync-attendance принимает человек (личная комната могла использоваться и для другого).');

        return self::SUCCESS;
    }
}
