<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Course;
use App\Models\Schedule;
use App\Services\Access\TelegramAdminNotifier;
use Illuminate\Console\Command;

/**
 * H5001 — курс продаёт пробное (trial_schedule_id задан), а открыть покупателю
 * нечего: trial_lesson_id пуст, или занятие уже прошло, а урока с записью этой
 * даты нет. Такой курс поднимается алертом, а не ждёт аудита.
 * Только чтение; `--notify` шлёт сводку админам в Telegram (ADMIN_TELEGRAM_ID).
 */
class WatchTrialTargets extends Command
{
    protected $signature = 'trial:target-watch {--notify : Отправить сводку админам в Telegram}';

    protected $description = 'H5001: курсы с пробным, у которых цель гранта пуста или без записи';

    public function handle(TelegramAdminNotifier $notifier): int
    {
        $problems = [];

        Course::query()
            ->whereNotNull('trial_schedule_id')
            ->orderBy('id')
            ->each(function (Course $course) use (&$problems): void {
                if ($course->trialGrantTarget()) {
                    return;
                }

                $schedule = Schedule::find($course->trial_schedule_id);
                $reason = match (true) {
                    ! $schedule || ! $schedule->start => 'событие расписания не найдено или без даты',
                    ! $course->trial_lesson_id => 'trial_lesson_id пуст',
                    default => 'занятие '.$schedule->start->format('d.m.Y').' прошло, урока с записью нет',
                };

                $problems[] = [$course->id, $course->title, $course->trial_lesson_id, $reason];
            });

        if ($problems === []) {
            $this->info('OK: у всех курсов с пробным цель гранта на месте.');

            return self::SUCCESS;
        }

        $this->table(['course_id', 'title', 'trial_lesson_id', 'причина'], $problems);

        if ($this->option('notify')) {
            $text = '🚨 <b>Пробное: открыть нечего</b> — курсов: '.count($problems)."\n\n";
            foreach ($problems as [$id, $title, , $reason]) {
                $text .= '• #'.$id.' «'.e((string) $title).'» — '.e($reason)."\n";
            }
            $text .= "\nПокупатель такого пробного получит оплату без доступа. Поправьте пробное занятие курса или снимите его с продажи.";
            $notifier->notifyAdmins($text);
        }

        return self::FAILURE;
    }
}
