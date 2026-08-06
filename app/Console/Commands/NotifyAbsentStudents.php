<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMessengerAlerts;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\ScheduleAttendanceNotice;
use App\Models\User;
use App\Services\ClassAttendanceService;
use Illuminate\Console\Command;

/**
 * Уведомление студентам, пропустившим занятие (опт-ин). Через N минут после конца
 * занятия берёт ожидаемых − пришедших (по ClassAttendanceService) и шлёт TG/VK.
 * Дедуп — schedules.absent_notified_at (сбрасывается при переносе занятия).
 */
class NotifyAbsentStudents extends Command
{
    protected $signature = 'classes:notify-absent {--minutes= : Задержка после конца занятия (по умолчанию из настроек)}';

    protected $description = 'Шлёт студентам, пропустившим занятие, напоминание в TG/VK (один раз на событие).';

    public function handle(ClassAttendanceService $attendance): int
    {
        $settings = MarketingSetting::cached();
        if (! $settings || ! $settings->absent_notify_enabled) {
            $this->info('Уведомления об отсутствии выключены — пропуск.');

            return self::SUCCESS;
        }

        $delay = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) ($settings->absent_notify_delay_minutes ?? 30);
        $delay = max(0, $delay);

        // Кандидаты: занятия за последние сутки, ещё не уведомлявшиеся, с аудиторией.
        $schedules = Schedule::query()
            ->whereNull('absent_notified_at')
            ->whereNotNull('start')
            ->whereBetween('start', [now()->subDay(), now()])
            ->where(fn ($q) => $q->whereNotNull('group_id')->orWhereNotNull('course_id'))
            ->get();

        $events = 0;
        $recipients = 0;

        foreach ($schedules as $schedule) {
            $end = $schedule->end ?? $schedule->start->copy()->addHours(Schedule::DEFAULT_DURATION_HOURS);

            // Занятие должно закончиться не меньше $delay минут назад.
            if ($end->copy()->addMinutes($delay)->isFuture()) {
                continue;
            }

            // H2317: кто заранее написал «не смогу» — не пингуем «скучали»:
            // предупреждение уже принято, запись всё равно в кабинете.
            $preNotifiedAbsentIds = ScheduleAttendanceNotice::query()
                ->where('schedule_id', $schedule->id)
                ->where('status', ScheduleAttendanceNotice::STATUS_ABSENT)
                ->pluck('user_id')
                ->all();

            $absentees = $attendance->forSchedule($schedule)['roster']
                ->where('status', 'absent')
                ->reject(fn ($row) => in_array($row['user']->id, $preNotifiedAbsentIds, true))
                ->map(fn ($row) => $row['user'])
                ->filter(fn (User $u) => $u->telegram_id || $u->vk_id);

            $text = $this->buildText($schedule);
            foreach ($absentees as $user) {
                SendMessengerAlerts::dispatch($user, $text);
                $recipients++;
            }

            $schedule->update(['absent_notified_at' => now()]);
            $events++;
        }

        $this->info("Уведомления об отсутствии: занятий {$events}, получателей {$recipients}.");

        return self::SUCCESS;
    }

    private function buildText(Schedule $schedule): string
    {
        $title = $schedule->title ?: 'Занятие';

        return "🙏 <b>Скучали по вам на занятии</b>\n\n"
            ."Похоже, вы пропустили <b>«{$title}»</b>. Запись появится в личном кабинете — "
            .'не теряйте темп, мы вас ждём на следующем!';
    }
}
