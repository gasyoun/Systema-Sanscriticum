<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Schedule;
use App\Services\Zoom\AttendanceRecorder;
use App\Services\Zoom\ZoomService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Pull-сверка посещаемости Zoom через Reports API — страховка от пропущенных
 * вебхуков participant_joined/left. По каждому курсу с единой ссылкой берём
 * прошедшие запуски встречи (instances), мапим на занятие (resolveForZoomEvent)
 * и апсертим участников. Идемпотентно (тот же ключ schedule_id+participant_uuid).
 */
class SyncZoomAttendance extends Command
{
    protected $signature = 'zoom:sync-attendance
        {--days=2 : За сколько последних дней забирать запуски встреч}
        {--course= : Ограничить одним курсом (id)}';

    protected $description = 'Догрузить посещаемость Zoom-занятий через Reports API (страховка вебхука)';

    public function handle(ZoomService $zoom, AttendanceRecorder $recorder): int
    {
        if (! $zoom->isConfigured()) {
            $this->warn('Zoom не сконфигурирован (ZOOM_ACCOUNT_ID/CLIENT_ID/CLIENT_SECRET) — пропуск.');

            return self::SUCCESS;
        }

        $since = now()->subDays(max(1, (int) $this->option('days')));

        $courses = Course::query()
            ->whereNotNull('zoom_meeting_id')
            ->when($this->option('course'), fn ($q) => $q->whereKey($this->option('course')))
            ->get();

        $instancesSeen = 0;
        $participantsSynced = 0;

        foreach ($courses as $course) {
            foreach ($zoom->pastMeetingInstances($course->zoom_meeting_id) as $instance) {
                $uuid = isset($instance['uuid']) ? (string) $instance['uuid'] : '';
                $startTime = $instance['start_time'] ?? null;

                if ($uuid === '') {
                    continue;
                }
                if ($startTime && Carbon::parse($startTime)->lt($since)) {
                    continue; // слишком старый запуск — не трогаем
                }

                $schedule = Schedule::resolveForZoomEvent($course->zoom_meeting_id, $uuid, $startTime);
                if ($schedule === null) {
                    continue;
                }

                $instancesSeen++;

                foreach ($zoom->meetingParticipants($uuid) as $participant) {
                    $recorder->recordReportRow($schedule->id, $participant);
                    $participantsSynced++;
                }
            }
        }

        $this->info("Сверка Zoom: запусков {$instancesSeen}, участников {$participantsSynced}.");

        return self::SUCCESS;
    }
}
