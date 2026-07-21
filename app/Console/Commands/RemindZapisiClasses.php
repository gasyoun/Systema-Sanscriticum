<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use Illuminate\Console\Command;

/**
 * Track C (H164): напоминание о занятии в Telegram-чат группы через @zapisi_ORSbot,
 * прямо из расписания. Берёт upcoming Schedule с group_id, шлёт в
 * group.telegram_chat_id (тот же маппинг «группа → чат», что и classes:post-group-link,
 * но отдельным ботом-записи). Заменяет ручную таблицу zapisi_class_schedules.
 *
 * Ничего не шлёт, если:
 *  - выключен флаг features.telegram_zapisi_bot (деплой-рубильник бота);
 *  - у группы не задан telegram_chat_id (skip без пометки — уйдёт, когда заполнят).
 * Дедуп — schedules.zapisi_reminded_at (сбрасывается при переносе start).
 */
class RemindZapisiClasses extends Command
{
    protected $signature = 'zapisi:remind-classes {--minutes= : За сколько минут до старта напоминать (по умолчанию — из настроек)}';

    protected $description = 'Напоминает о занятии в чат группы через @zapisi_ORSbot за N минут до старта, один раз на событие.';

    public function handle(): int
    {
        if (! config('features.telegram_zapisi_bot')) {
            $this->info('Track C (@zapisi_ORSbot) выключен через TELEGRAM_ZAPISI_BOT_ENABLED — пропуск.');

            return self::SUCCESS;
        }

        $settings = MarketingSetting::cached();

        $lead = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) ($settings?->zapisi_reminder_lead_minutes ?? 60);
        $lead = max(1, $lead);

        $schedules = Schedule::query()
            ->with(['group', 'course'])
            ->whereNull('zapisi_reminded_at')
            ->whereNotNull('group_id')
            ->whereBetween('start', [now(), now()->addMinutes($lead)])
            ->get();

        $template = trim((string) ($settings?->zapisi_reminder_template ?? '')) !== ''
            ? (string) $settings->zapisi_reminder_template
            : 'Напоминаем: занятие «{title}» у группы {group} начнётся сегодня в {time} (МСК).';

        $sent = 0;

        foreach ($schedules as $schedule) {
            $group = $schedule->group;

            // Нет чата группы — слать некуда; НЕ помечаем, чтобы после заполнения
            // telegram_chat_id напоминание всё же ушло (как в classes:post-group-link).
            if ($group === null || empty($group->telegram_chat_id)) {
                continue;
            }

            SendZapisiBotMessageJob::dispatch(
                (string) $group->telegram_chat_id,
                $this->renderText($schedule, $group, $template),
            );

            $schedule->update(['zapisi_reminded_at' => now()]);
            $sent++;
        }

        $this->info("Напоминаний @zapisi_ORSbot отправлено в чаты групп: {$sent}.");

        return self::SUCCESS;
    }

    private function renderText(Schedule $schedule, Group $group, string $template): string
    {
        $link = $schedule->zoom_join_url ?: ($schedule->link ?: $schedule->course?->zoom_link);

        return strtr($template, [
            '{title}' => (string) ($schedule->title ?: 'Занятие'),
            '{time}' => $schedule->start->format('H:i'),
            '{group}' => (string) ($group->name ?? ''),
            '{link}' => (string) ($link ?? ''),
        ]);
    }
}
