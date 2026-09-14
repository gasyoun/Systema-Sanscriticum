<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendZapisiBotMessageJob;
use App\Models\Group;
use App\Models\MarketingSetting;
use App\Models\Schedule;
use App\Models\TelegramChatPost;
use App\Services\TeacherVacation;
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
 *    дубль-гвардия с classes:post-group-link: один «Скоро занятие» на чат;
 *  - у занятия нет ссылки (zoom_join_url/link/course.zoom_link пусты) — инцидент
 *    02-09-2026: напоминание с висящим «по ссылке:» без самой ссылки; пропускаем
 *    без пометки, чтобы после появления ссылки напоминание всё же ушло.
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
            ->orderBy('id')
            ->get();

        $template = trim((string) ($settings?->zapisi_reminder_template ?? '')) !== ''
            ? (string) $settings->zapisi_reminder_template
            : self::DEFAULT_TEMPLATE;

        $sent = 0;

        // Инцидент 11-09-2026 (курс 348 «Бхагавадгита 4 цикл»): в расписании
        // оказались ДВЕ живые строки на один слот (перенос лёг на существующую
        // строку серии), и каждая ушла отдельным напоминанием — два поста в один
        // чат за секунду, различие только в номере титула (#76/#77), поэтому
        // TelegramSendGuard по тексту их не склеил. Группируем по слоту
        // (группа + старт): один пост на слот, помечаются ВСЕ строки слота.
        // Коллизия строк больше не доходит до студентов, даже если её заведут руками.
        $slots = $schedules->groupBy(fn (Schedule $s): string => ($s->group_id ?? 0).':'.($s->start?->format('Y-m-d H:i') ?? ''));

        foreach ($slots as $slotRows) {
            $rows = $slotRows->sortBy('id')->values();
            $schedule = $rows->first();
            $group = $schedule->group;

            // Нет чата группы — слать некуда; НЕ помечаем, чтобы после заполнения
            // telegram_chat_id напоминание всё же ушло (как в classes:post-group-link).
            if ($group === null || empty($group->telegram_chat_id)) {
                continue;
            }

            // Зеркальная дубль-гвардия (диагноз 26-08-2026): если автопостинг ссылки
            // (classes:post-group-link, T-15) уже постит ЛЮБУЮ строку слота —
            // не отправляем второй «Скоро занятие» в тот же чат.
            if ($rows->contains(fn (Schedule $r): bool => $r->group_link_posted_at !== null)) {
                continue;
            }

            // H4253: каникулы — групповой флаг (H3790) или окно преподавателя.
            // Пропуск БЕЗ пометки: после снятия флага/окна напоминание уйдёт.
            if ($group->is_on_vacation) {
                continue;
            }

            if (TeacherVacation::covers($group, $schedule->start)) {
                continue;
            }

            // Инцидент 02-09-2026 (кейс 1620, курс 401): серия занятий нового учебного
            // года сгенерирована без ссылок (link/zoom_join_url/course.zoom_link пусты),
            // и в чат ушло напоминание с висящим «Подключится к занятию можно по
            // ссылке:» без самой ссылки. Без ссылки напоминание бесполезно —
            // пропускаем БЕЗ пометки, чтобы после появления ссылки оно всё же ушло.
            $link = (string) ($schedule->zoom_join_url ?: ($schedule->link ?: $schedule->course?->zoom_link) ?: '');
            if ($link === '') {
                report(new \RuntimeException(sprintf(
                    'zapisi:remind-classes: schedule #%d («%s», start %s) has no join link (zoom_join_url/link/course.zoom_link all empty) — reminder skipped without marking, will send once a link appears.',
                    $schedule->id,
                    $schedule->title ?: 'Занятие',
                    $schedule->start->format('Y-m-d H:i'),
                )));

                continue;
            }

            SendZapisiBotMessageJob::dispatch(
                (string) $group->telegram_chat_id,
                $this->renderText($schedule, $group, $template, $link),
                $schedule->id,
                TelegramChatPost::KIND_ZAPISI_REMINDER,
            );

            // Помечаем ВСЕ строки слота: дедуп по строке, отправка по слоту.
            foreach ($rows as $row) {
                $row->update(['zapisi_reminded_at' => now()]);
            }

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
    private function renderText(Schedule $schedule, Group $group, string $template, string $link): string
    {
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
