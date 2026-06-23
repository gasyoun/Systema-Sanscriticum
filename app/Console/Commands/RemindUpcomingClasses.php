<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMessengerAlerts;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RemindUpcomingClasses extends Command
{
    protected $signature = 'classes:remind-upcoming {--minutes=60 : За сколько минут до старта напоминать}';

    protected $description = 'Шлёт студентам напоминание в TG/VK о скором занятии (по Schedule), один раз на событие.';

    public function handle(): int
    {
        // Рубильник в админке (MarketingSetting). Нет настроек — считаем включённым.
        $settings = MarketingSetting::cached();
        if ($settings && ! $settings->class_reminders_enabled) {
            $this->info('Напоминания о занятиях отключены в настройках — пропуск.');

            return self::SUCCESS;
        }

        $lead = max(1, (int) $this->option('minutes'));

        // Занятия, стартующие в ближайшие $lead минут и ещё не напомненные.
        $schedules = Schedule::query()
            ->with(['group', 'course'])
            ->whereNull('reminded_at')
            ->whereBetween('start', [now(), now()->addMinutes($lead)])
            ->get();

        $events = 0;
        $recipients = 0;

        foreach ($schedules as $schedule) {
            $audience = $this->audienceFor($schedule);

            // Без адресной аудитории (нет группы и курса) — глобальные пуши не шлём.
            if ($audience === null) {
                continue;
            }

            $text = $this->buildText($schedule);

            $sent = 0;
            $audience->chunkById(200, function ($users) use ($text, &$sent): void {
                foreach ($users as $user) {
                    SendMessengerAlerts::dispatch($user, $text);
                    $sent++;
                }
            });

            // Отмечаем занятие как «напомнили» в любом случае, чтобы не зациклиться
            // на событии без получателей (иначе оно висело бы в окне до старта).
            $schedule->update(['reminded_at' => now()]);

            $events++;
            $recipients += $sent;
        }

        $this->info("Напоминания о занятиях: событий {$events}, получателей {$recipients}.");

        return self::SUCCESS;
    }

    /**
     * Кому слать по конкретному занятию. Только студенты с привязанным
     * мессенджером (иначе SendMessengerAlerts всё равно no-op).
     */
    private function audienceFor(Schedule $schedule): ?Builder
    {
        $query = User::query()->where(function (Builder $q): void {
            $q->whereNotNull('telegram_id')->orWhereNotNull('vk_id');
        });

        if ($schedule->group_id) {
            return $query->whereHas('groups', fn (Builder $q) => $q->where('groups.id', $schedule->group_id));
        }

        if ($schedule->course_id) {
            // Студенты курса — через pivot course_group (Group::courses()).
            return $query->whereHas('groups.courses', fn (Builder $q) => $q->where('courses.id', $schedule->course_id));
        }

        return null;
    }

    private function buildText(Schedule $schedule): string
    {
        $title = $schedule->title ?: 'Занятие';
        $time = $schedule->start->format('H:i');

        $text = "🔔 <b>Скоро занятие</b>\n\n";
        $text .= "Намасте! Занятие <b>«{$title}»</b> начнётся сегодня в <b>{$time}</b> (МСК).";

        if ($link = $schedule->link) {
            $text .= "\n\n<a href='{$link}'>Подключиться к занятию</a>";
        }

        return $text;
    }
}
