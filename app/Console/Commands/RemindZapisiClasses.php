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
 *  - у группы не задан telegram_chat_id (skip без пометки — уйдёт, когда заполнят);
 *  - по занятию уже ушёл автопостинг ссылки (group_link_posted_at) — зеркальная
 *    дубль-гвардия с classes:post-group-link: один «Скоро занятие» на чат.
 * Дедуп — schedules.zapisi_reminded_at (сбрасывается при переносе start).
 */
class RemindZapisiClasses extends Command
{
    protected $signature = 'zapisi:remind-classes {--minutes= : За сколько минут до старта напоминать (по умолчанию — из настроек)}';

    protected $description = 'Напоминает о занятии в чат группы через @zapisi_ORSbot за N минут до старта, один раз на событие.';

    /**
     * Шаблон по умолчанию. Сообщение уходит с parse_mode=HTML, поэтому разметка
     * работает и здесь, и в пользовательском шаблоне из админки — раньше дефолт был
     * сплошной строкой, и напоминание выглядело стеной текста. Стиль тот же, что у
     * соседнего classes:post-group-link (см. PostClassLinkToGroupChat::buildText).
     */
    public const DEFAULT_TEMPLATE = "🔔 <b>Скоро занятие</b>\n\n"
        ."Занятие <b>«{title}»</b> у группы {group} начнётся сегодня в <b>{time}</b> (МСК).\n\n"
        .'{join}';

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
            : self::DEFAULT_TEMPLATE;

        $sent = 0;

        foreach ($schedules as $schedule) {
            $group = $schedule->group;

            // Нет чата группы — слать некуда; НЕ помечаем, чтобы после заполнения
            // telegram_chat_id напоминание всё же ушло (как в classes:post-group-link).
            if ($group === null || empty($group->telegram_chat_id)) {
                continue;
            }

            // Зеркальная дубль-гвардия (диагноз 26-08-2026): если автопостинг ссылки
            // (classes:post-group-link, T-15) успел раньше нас — при нестандартных
            // lead-настройках — не отправляем второй «Скоро занятие» в тот же чат.
            if ($schedule->group_link_posted_at !== null) {
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

    /**
     * Подстановки ЭКРАНИРУЮТСЯ: сообщение уходит с parse_mode=HTML, и один
     * амперсанд в названии курса («Грамматика & чтение») заставлял Telegram
     * отвергнуть весь запрос с «can't parse entities» — напоминание не уходило
     * вовсе, а в логах оседало предупреждение джоба. Разметку задаёт шаблон,
     * данные — только текст.
     *
     * {join} — готовая ссылка-кнопка: пустая строка, когда подключаться некуда,
     * чтобы в сообщении не оставалось висящего «Подключиться» в никуда.
     */
    private function renderText(Schedule $schedule, Group $group, string $template): string
    {
        $link = (string) ($schedule->zoom_join_url ?: ($schedule->link ?: $schedule->course?->zoom_link) ?: '');

        return strtr($template, [
            '{title}' => $this->escape($schedule->title ?: 'Занятие'),
            '{time}' => $schedule->start->format('H:i'),
            '{group}' => $this->escape((string) ($group->name ?? '')),
            '{link}' => $this->escape($link),
            '{join}' => $link !== ''
                ? '<a href="'.$this->escape($link).'">Подключиться к занятию</a>'
                : '',
        ]);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
