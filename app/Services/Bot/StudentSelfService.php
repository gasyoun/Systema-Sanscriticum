<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\Schedule;
use App\Models\User;

/**
 * Детерминированные self-service ответы студенту в боте (TG/VK) — данные, которые
 * ИИ-куратору выдумывать запрещено (личные группы/расписание). Перехватываются в
 * webhook-контроллерах ДО передачи вопроса в CuratorAi.
 */
class StudentSelfService
{
    /**
     * Точные фразы-команды «покажи мои группы».
     *
     * @var list<string>
     */
    private const GROUP_PHRASES = [
        '/groups',
        '/mygroups',
        'мои группы',
        'моя группа',
        'мои курсы',
        'мой курс',
        'в каких группах',
        'в какой я группе',
        'какие у меня группы',
        'какие у меня курсы',
        'где я учусь',
        'мое расписание',
        'моё расписание',
        'мои занятия',
    ];

    /**
     * Похоже ли сообщение на запрос «в каких я группах / моё расписание».
     * Консервативно, чтобы не перехватывать обычные вопросы к ИИ.
     */
    public function matchesGroupsIntent(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        if ($t === '') {
            return false;
        }

        foreach (self::GROUP_PHRASES as $phrase) {
            if (str_contains($t, $phrase)) {
                return true;
            }
        }

        // Мягкий fallback: про «групп(а/ы)» и явно про себя.
        $aboutSelf = str_contains($t, 'мо') || str_contains($t, 'каки') || str_contains($t, 'где');

        return str_contains($t, 'групп') && $aboutSelf;
    }

    /**
     * Сводка по группам студента: название группы, курс(ы), ближайшее занятие.
     * Возвращает Telegram-HTML (для VK теги срежет TelegramFormatter::toPlain).
     */
    public function groupsSummary(User $user, string $source = 'telegram'): string
    {
        $groups = $user->groups()->with('courses')->get();

        if ($groups->isEmpty()) {
            $url = rtrim((string) config('app.url'), '/').'/dvaram';

            return "📚 <b>Ваши группы</b>\n\n"
                .'Пока вы не состоите ни в одной учебной группе. После оплаты курса '
                ."доступ и группа появятся автоматически.\n\n"
                ."<a href='{$url}'>Открыть личный кабинет</a>";
        }

        $lines = ["📚 <b>Ваши группы</b>\n"];

        foreach ($groups as $group) {
            $lines[] = '• <b>'.e($group->name).'</b>';

            $courses = $group->courses->pluck('title')->filter()->implode(', ');
            if ($courses !== '') {
                $lines[] = '   Курс: '.e($courses);
            }

            $next = Schedule::query()
                ->where('group_id', $group->id)
                ->where('start', '>=', now())
                ->orderBy('start')
                ->first();

            if ($next) {
                $when = $next->start->format('d.m в H:i');
                $lines[] = "   🔔 Ближайшее занятие: {$when} (МСК)";
                // Трекинг-ссылка с подписью и user id — фиксирует переход для
                // учёта посещаемости и редиректит на настоящий Zoom-URL.
                if ($link = $next->trackedJoinUrlFor($user, $source)) {
                    $lines[] = "   <a href='{$link}'>Подключиться к занятию</a>";
                }
            } else {
                $lines[] = '   🔔 Ближайшее занятие: не запланировано';
            }

            $lines[] = ''; // пустая строка-разделитель между группами
        }

        return rtrim(implode("\n", $lines));
    }
}
