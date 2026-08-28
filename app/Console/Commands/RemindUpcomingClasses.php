<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendMessengerAlerts;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\User;
use App\Services\ClassRoster;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class RemindUpcomingClasses extends Command
{
    protected $signature = 'classes:remind-upcoming {--minutes= : За сколько минут до старта напоминать (по умолчанию — из настроек админки)}';

    protected $description = 'Шлёт студентам напоминание в TG/VK о скором занятии (по Schedule), один раз на событие.';

    public function handle(): int
    {
        // Рубильник в админке (MarketingSetting). Нет настроек — считаем включённым.
        $settings = MarketingSetting::cached();
        if ($settings && ! $settings->class_reminders_enabled) {
            $this->info('Напоминания о занятиях отключены в настройках — пропуск.');

            return self::SUCCESS;
        }

        // Окно: явная опция --minutes перекрывает; иначе — значение из настроек (дефолт 60).
        $lead = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) ($settings?->class_reminder_lead_minutes ?? 60);
        $lead = max(1, $lead);

        // Занятия, стартующие в ближайшие $lead минут и ещё не напомненные.
        $schedules = Schedule::query()
            ->with(['group', 'course'])
            ->whereNull('reminded_at')
            ->whereBetween('start', [now(), now()->addMinutes($lead)])
            ->get();

        $events = 0;
        $recipients = 0;

        foreach ($schedules as $schedule) {
            // Дубль-гвардия каналов (диагноз 28-08-2026): группа с Telegram-чатом уже
            // получает «Скоро занятие» от zapisi:remind-classes в тот же T-60 — персональный
            // пинг каждому студенту с привязанным Telegram приходит через минуту и читается
            // как повтор. Пропускаем БЕЗ пометки: выключение рубильника вернёт ЛС в том же
            // окне, а перенос занятия (сброс reminded_at) тут ничего не ломает.
            if ($settings?->dm_suppressed_when_group_chat
                && $schedule->group !== null
                && ! empty($schedule->group->telegram_chat_id)) {
                continue;
            }

            $audience = $this->audienceFor($schedule);

            // Без адресной аудитории (нет группы и курса) — глобальные пуши не шлём.
            if ($audience === null) {
                continue;
            }

            $sent = 0;
            $audience->chunkById(200, function ($users) use ($schedule, &$sent): void {
                foreach ($users as $user) {
                    // Текст персональный: ссылка «Подключиться» подписана на этого
                    // студента (учёт посещаемости через трекинг-редирект).
                    SendMessengerAlerts::dispatch($user, $this->buildText($schedule, $user));
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
     * Кому слать по конкретному занятию: ожидаемый ростер (ClassRoster) с фильтром
     * по привязанному мессенджеру (иначе SendMessengerAlerts всё равно no-op).
     */
    private function audienceFor(Schedule $schedule): ?Builder
    {
        return ClassRoster::query($schedule)?->where(function (Builder $q): void {
            $q->whereNotNull('telegram_id')->orWhereNotNull('vk_id');
        });
    }

    private function buildText(Schedule $schedule, User $user): string
    {
        $title = $schedule->title ?: 'Занятие';
        $time = $schedule->start->format('H:i');

        $text = "🔔 <b>Скоро занятие</b>\n\n";
        $text .= "Намасте! Занятие <b>«{$title}»</b> начнётся сегодня в <b>{$time}</b> (МСК).";

        // Подписанная трекинг-ссылка на этого студента (учёт посещаемости).
        if ($link = $schedule->trackedJoinUrlFor($user, 'reminder')) {
            $text .= "\n\n<a href='{$link}'>Подключиться к занятию</a>";
        }

        return $text;
    }
}
