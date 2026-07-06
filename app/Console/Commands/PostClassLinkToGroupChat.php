<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendTelegramChatMessageJob;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use Illuminate\Console\Command;

/**
 * P0 автоматизации «Отдела заботы»: за N минут до занятия постит ссылку
 * подключения ОДНИМ сообщением в Telegram-чат группы (в отличие от
 * classes:remind-upcoming, который шлёт персональные ЛС каждому студенту).
 *
 * Снимает самый механический класс вопросов из анализа чата (Zoom-ссылка,
 * «где ссылка», часть «когда занятие»). Ничего не шлёт, если:
 *  - выключен флаг class_link_autopost_enabled (MarketingSetting);
 *  - у группы не задан telegram_chat_id;
 *  - у занятия нет ни своей ссылки, ни zoom_join_url, ни course.zoom_link.
 * Дедуп — schedules.group_link_posted_at (сбрасывается при переносе start).
 */
class PostClassLinkToGroupChat extends Command
{
    protected $signature = 'classes:post-group-link {--minutes= : За сколько минут до старта постить (по умолчанию — из настроек админки)}';

    protected $description = 'Постит ссылку на занятие в Telegram-чат группы за N минут до старта, один раз на событие.';

    public function handle(): int
    {
        $settings = MarketingSetting::cached();
        if ($settings && ! $settings->class_link_autopost_enabled) {
            $this->info('Авто-постинг ссылки в чат группы отключён в настройках — пропуск.');

            return self::SUCCESS;
        }

        $lead = $this->option('minutes') !== null
            ? (int) $this->option('minutes')
            : (int) ($settings?->class_link_autopost_lead_minutes ?? 15);
        $lead = max(1, $lead);

        $schedules = Schedule::query()
            ->with(['group', 'course'])
            ->whereNull('group_link_posted_at')
            ->whereNotNull('group_id')
            ->whereBetween('start', [now(), now()->addMinutes($lead)])
            ->get();

        $posted = 0;

        foreach ($schedules as $schedule) {
            $group = $schedule->group;

            // Нет чата группы — постить некуда; НЕ помечаем как отправленное,
            // чтобы после заполнения telegram_chat_id ссылка всё же ушла.
            if ($group === null || empty($group->telegram_chat_id)) {
                continue;
            }

            // Приоритет: своя ссылка занятия → авто-Zoom этой сессии → общая ссылка курса.
            $link = $schedule->zoom_join_url ?: ($schedule->link ?: $schedule->course?->zoom_link);
            if (empty($link)) {
                continue;
            }

            SendTelegramChatMessageJob::dispatch(
                (string) $group->telegram_chat_id,
                $this->buildText($schedule, $link),
            );

            $schedule->update(['group_link_posted_at' => now()]);
            $posted++;
        }

        $this->info("Ссылок на занятие отправлено в чаты групп: {$posted}.");

        return self::SUCCESS;
    }

    private function buildText(Schedule $schedule, string $link): string
    {
        $title = htmlspecialchars($schedule->title ?: 'Занятие', ENT_QUOTES, 'UTF-8');
        $time = $schedule->start->format('H:i');

        return "🔔 <b>Скоро занятие</b>\n\n"
            ."Намасте! Занятие <b>«{$title}»</b> начнётся сегодня в <b>{$time}</b> (МСК).\n\n"
            ."<a href=\"{$link}\">Подключиться к занятию</a>";
    }
}
