<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\Group;
use App\Models\HomeworkSubmission;
use App\Models\Schedule;
use App\Models\SupportAnswerSuggestion;
use App\Models\User;
use App\Services\AttendanceNoticeService;
use App\Services\Support\SupportAnswerFactResolver;
use App\Services\Support\SupportAnswerSuggester;

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
            ."🗓 <b>ближайшие эфиры</b> — открытые стримы-анонсы · «подписаться на эфиры» / «отписаться от эфиров»\n"
            ."🔗 ссылка на занятие / запись / расписание — из ваших групп, без ИИ\n"
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

    /**
     * A/B/C с живым фактом LMS (Zoom / запись / расписание) — тот же
     * SupportAnswerSuggester, что лички саппорта. Без факта возвращает null,
     * вызывающий идёт в ИИ. Деньги (D) не перехватываются.
     */
    public function lmsFactReply(User $user, string $text): ?string
    {
        $category = app(SupportAnswerSuggester::class)->categorize($text);
        if (! in_array($category, [
            SupportAnswerSuggestion::CATEGORY_ZOOM,
            SupportAnswerSuggestion::CATEGORY_RECORDING,
            SupportAnswerSuggestion::CATEGORY_SCHEDULE,
        ], true)) {
            return null;
        }

        $resolved = app(SupportAnswerFactResolver::class)->resolve($category, $user);
        $draft = trim((string) ($resolved['draft'] ?? ''));

        return $draft === '' ? null : e($draft);
    }

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
     * Открытые стримы-анонсы ОРС (H3576 §2): курс бесплатных эфиров и группа
     * подписки на них. Подписка = активное участие в группе потока: ростер
     * classes:remind-upcoming берёт активных участников групп курса, поэтому
     * отдельная таблица подписок не нужна.
     */
    public const STREAMS_COURSE_SLUG = 'otkrytye-zaniatiia-i-vebinary';

    public const STREAMS_GROUP_SLUG = 'otkrytye-efiry-ors';

    /**
     * Точные фразы-команды «ближайшие эфиры». Проверяются ПОСЛЕ интентов
     * подписки/отписки — те содержат слово «эфир(ы)» внутри себя.
     *
     * @var list<string>
     */
    private const STREAMS_PHRASES = [
        '/efiry',
        'эфиры',
        'эфир',
        'стрим',
        'вебинар',
        'анонс курсов',
    ];

    private const STREAMS_SUBSCRIBE_PHRASES = [
        'подписаться на эфиры',
        'подписка на эфиры',
        'подпишись на эфиры',
        'напоминайте об эфирах',
        'напоминать об эфирах',
        'хочу ходить на эфиры',
        'хочу узнавать про стримы',
    ];

    private const STREAMS_UNSUBSCRIBE_PHRASES = [
        'отписаться от эфиров',
        'отписка от эфиров',
        'не хочу получать анонсы',
        'не напоминай об эфирах',
        'не напоминать об эфирах',
        'убрать эфиры',
    ];

    /** Похоже ли сообщение на команду подписки на открытые эфиры. */
    public function matchesStreamsSubscribeIntent(string $text): bool
    {
        return self::containsAny($text, self::STREAMS_SUBSCRIBE_PHRASES);
    }

    /** Похоже ли сообщение на команду отписки от открытых эфиров. */
    public function matchesStreamsUnsubscribeIntent(string $text): bool
    {
        return self::containsAny($text, self::STREAMS_UNSUBSCRIBE_PHRASES);
    }

    /** Похоже ли сообщение на запрос расписания открытых эфиров. */
    public function matchesStreamsIntent(string $text): bool
    {
        return self::containsAny($text, self::STREAMS_PHRASES);
    }

    /**
     * Расписание открытых эфиров + статус подписки пользователя. Только факты
     * из БД (курс по слагу), Telegram-HTML.
     */
    public function streamsSummary(User $user, string $source = 'telegram'): string
    {
        $upcoming = Schedule::query()
            ->whereHas('course', fn ($q) => $q->where('courses.slug', self::STREAMS_COURSE_SLUG))
            ->where('start', '>=', now())
            ->orderBy('start')
            ->limit(5)
            ->get();

        $lines = ["🗓 <b>Открытые эфиры ОРС</b>\n"];

        if ($upcoming->isEmpty()) {
            $lines[] = 'Ближайшие даты ещё не объявлены — подпишитесь, и мы пришлём '
                .'напоминание, как только эфир появится в расписании.';
        } else {
            foreach ($upcoming as $index => $schedule) {
                $when = $schedule->start->format('d.m.Y').' в '.$schedule->start->format('H:i');
                $title = $schedule->title ?: 'Стрим-анонс курсов ОРС';
                $lines[] = ($index === 0 ? '▶️ ' : '• ').e($title).' — '.$when.' МСК';

                // Для ближайшего эфира — трекинг-ссылка подключиться (подписана
                // на студента) или страница курса для записи новичку.
                if ($index === 0) {
                    if ($this->isSubscribedToStreams($user)) {
                        if ($link = $schedule->trackedJoinUrlFor($user, $source)) {
                            $lines[] = "   <a href='{$link}'>Подключиться к эфиру</a>";
                        }
                    } else {
                        $url = rtrim((string) config('app.url'), '/')
                            .'/k/'.self::STREAMS_COURSE_SLUG;
                        $lines[] = "   <a href='{$url}'>Записаться бесплатно</a>";
                    }
                }
            }
        }

        $lines[] = '';

        $lines[] = $this->isSubscribedToStreams($user)
            ? "🔔 Вы подписаны: напомним за час до каждого эфира.\n<i>Отписаться — напишите «отписаться от эфиров».</i>"
            : '<i>Напоминания о каждом эфире: напишите «подписаться на эфиры».</i>';

        return rtrim(implode("\n", $lines));
    }

    /**
     * Подписка на открытые эфиры: присоединить к группе потока (idempotent,
     * повторная подписка после отписки гасит left_at). Возвращает текст ответа.
     */
    public function subscribeToStreams(User $user): string
    {
        $group = $this->streamsGroup();
        $already = $this->isSubscribedToStreams($user);

        if (! $already) {
            $existing = $user->groups()->where('groups.id', $group->id)->first();
            if ($existing) {
                $user->groups()->updateExistingPivot($group->id, [
                    'left_at' => null,
                    'left_reason' => null,
                ]);
            } else {
                $user->groups()->attach($group->id);
            }
        }

        $next = Schedule::query()
            ->whereHas('course', fn ($q) => $q->where('courses.slug', self::STREAMS_COURSE_SLUG))
            ->where('start', '>=', now())
            ->orderBy('start')
            ->first();

        $header = $already
            ? 'Вы уже подписаны на открытые эфиры 🔔'
            : 'Готово — вы подписаны на открытые эфиры 🔔';

        $whenLine = $next
            ? "\n\nБлижайший: <b>".e($next->title ?: 'Стрим-анонс курсов ОРС').'</b> — '
                .$next->start->format('d.m.Y').' в '.$next->start->format('H:i').' МСК.'
            : "\n\nКак только появится новый эфир — напомним.";

        return $header.$whenLine."\n\n<i>Пришлём сообщение за час до начала. "
            .'Отписаться — напишите «отписаться от эфиров».</i>';
    }

    /** Отписка от открытых эфиров (участие фиксируется через left_at). */
    public function unsubscribeFromStreams(User $user): string
    {
        $group = $this->streamsGroup();

        $attached = $user->groups()->where('groups.id', $group->id)->first();
        if (! $attached) {
            return 'Вы и не были подписаны на открытые эфиры. Хотите смотреть расписание — '
                .'напишите «ближайшие эфиры».';
        }

        $active = $user->groups()
            ->where('groups.id', $group->id)
            ->wherePivotNull('left_at')
            ->exists();

        if ($active) {
            $user->groups()->updateExistingPivot($group->id, [
                'left_at' => now(),
                'left_reason' => 'bot_streams_unsubscribe',
            ]);
        }

        return 'Отписал — напоминания об открытых эфирах больше не придут 🙏 '
            .'<i>Передумаете: напишите «подписаться на эфиры».</i>';
    }

    /** Активен ли пользователь в группе открытых эфиров. */
    public function isSubscribedToStreams(User $user): bool
    {
        $group = Group::query()->where('slug', self::STREAMS_GROUP_SLUG)->first();
        if (! $group) {
            return false;
        }

        return $user->groups()
            ->where('groups.id', $group->id)
            ->wherePivotNull('left_at')
            ->exists();
    }

    /** Группа потока открытых эфиров (создаётся при первом обращении). */
    private function streamsGroup(): Group
    {
        return Group::query()->firstOrCreate(
            ['slug' => self::STREAMS_GROUP_SLUG],
            ['name' => 'Открытые эфиры ОРС (стрим-анонсы)', 'status' => 'forming'],
        );
    }

    private static function containsAny(string $text, array $phrases): bool
    {
        $t = mb_strtolower(trim($text));

        if ($t === '') {
            return false;
        }

        foreach ($phrases as $phrase) {
            if (str_contains($t, $phrase)) {
                return true;
            }
        }

        return false;
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
