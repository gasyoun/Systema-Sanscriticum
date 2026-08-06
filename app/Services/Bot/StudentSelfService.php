<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\HomeworkSubmission;
use App\Models\Schedule;
use App\Models\User;
use App\Services\AttendanceNoticeService;

/**
 * Детерминированные self-service ответы студенту в боте (TG/VK) — данные, которые
 * ИИ-куратору выдумывать запрещено (личные группы/расписание). Перехватываются в
 * webhook-контроллерах ДО передачи вопроса в CuratorAi.
 */
class StudentSelfService
{
    public function __construct(
        private ?AttendanceNoticeService $attendanceNotices = null,
    ) {
        $this->attendanceNotices ??= app(AttendanceNoticeService::class);
    }

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
     * Точные фразы-команды справочного меню. Намеренно НЕ включает «помощь» —
     * это слово уже зарезервировано под передачу живому куратору (HUMAN_TRIGGERS
     * в TelegramWebhookController/ProcessVkBotMessage/StudentChatService), и
     * пересечение сделало бы одно из двух поведений непредсказуемым.
     *
     * @var list<string>
     */
    private const HELP_PHRASES = [
        '/help',
        '/menu',
        'меню',
        'что ты умеешь',
        'список команд',
        'какие команды',
    ];

    /** Похоже ли сообщение на запрос справочного меню бота. */
    public function matchesHelpIntent(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        if ($t === '') {
            return false;
        }

        foreach (self::HELP_PHRASES as $phrase) {
            if (str_contains($t, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Детерминированное справочное меню — фиксированный текст, ИИ тут не участвует.
     */
    public function helpMenu(): string
    {
        $noticeLine = "📅 <b>не смогу на урок</b> / <b>опоздаю</b> / <b>уйду раньше</b> / <b>возможно не буду</b> — предупредить о занятии заранее\n"
            ."   (или /absent · /late · /early · /maybe · /coming)\n";

        return "🤖 <b>Что я умею</b>\n\n"
            ."📚 <b>мои группы</b> — ваши группы, курсы и ближайшее занятие\n"
            ."📝 <b>мои задания</b> — статус домашних работ\n"
            .$noticeLine
            ."🙋 «позови куратора» — переключиться на живого человека\n\n"
            .'Обычные вопросы по обучению, курсам, оплате и доступу я тоже понимаю — просто напишите их своими словами.';
    }

    /**
     * Предупреждение о ближайшем занятии (H2317): не приду / не уверен / опоздаю / уйду раньше.
     */
    public function matchesAttendanceNoticeIntent(string $text): bool
    {
        return $this->attendanceNotices->matchIntent($text) !== null;
    }

    /**
     * @return array{ok: bool, text: string}
     */
    public function handleAttendanceNotice(
        User $user,
        string $text,
        string $source = 'telegram',
        ?int $preferGroupId = null,
    ): array {
        $result = $this->attendanceNotices->handleBotMessage($user, $text, $source, $preferGroupId);

        return [
            'ok' => $result['ok'],
            'text' => $result['text'],
        ];
    }

    /**
     * Точные фразы-команды «покажи мои домашние задания».
     *
     * @var list<string>
     */
    private const HOMEWORK_PHRASES = [
        '/homework',
        '/myhomework',
        '/hw',
        'мои задания',
        'моё задание',
        'мое задание',
        'мои дз',
        'моя дз',
        'домашние задания',
        'домашняя работа',
        'домашка',
        'статус дз',
        'статус домашки',
        'статус домашней работы',
    ];

    /** Похоже ли сообщение на запрос статуса домашних заданий. */
    public function matchesHomeworkIntent(string $text): bool
    {
        $t = mb_strtolower(trim($text));

        if ($t === '') {
            return false;
        }

        foreach (self::HOMEWORK_PHRASES as $phrase) {
            if (str_contains($t, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Сводка по домашним заданиям студента: урок, статус, ссылка. Только уже
     * начатые/отправленные работы (HomeworkSubmission) — какие уроки вообще
     * требуют ДЗ, бот не отслеживает.
     */
    public function homeworkSummary(User $user): string
    {
        $submissions = HomeworkSubmission::query()
            ->where('user_id', $user->id)
            ->with(['lesson', 'course'])
            ->orderByDesc('last_activity_at')
            ->get();

        if ($submissions->isEmpty()) {
            return "📝 <b>Ваши задания</b>\n\n"
                .'Пока нет отправленных домашних работ. Откройте урок в личном кабинете '
                .'и отправьте задание — здесь появится его статус.';
        }

        $lines = ["📝 <b>Ваши задания</b>\n"];

        foreach ($submissions as $submission) {
            $title = $submission->lesson?->title ?? 'Урок';
            $lines[] = self::homeworkStatusEmoji($submission->status).' <b>'.e($title).'</b> — '.e($submission->statusLabel());

            if ($submission->course && $submission->lesson) {
                $url = route('student.lesson', [$submission->course->slug, $submission->lesson_id]);
                $lines[] = "   <a href='{$url}'>Открыть урок</a>";
            }

            $lines[] = ''; // пустая строка-разделитель между заданиями
        }

        return rtrim(implode("\n", $lines));
    }

    private static function homeworkStatusEmoji(string $status): string
    {
        return match ($status) {
            HomeworkSubmission::STATUS_ACCEPTED => '✅',
            HomeworkSubmission::STATUS_NEEDS_REVISION => '✍️',
            HomeworkSubmission::STATUS_SUBMITTED => '🕓',
            default => '📄',
        };
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
