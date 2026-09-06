<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Schedule;
use App\Models\SupportAnswerSuggestion;
use App\Models\Tariff;
use App\Models\User;
use App\Services\AccessDiagnosticsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Резолвер фактов для FAQ-суггестера (H247): по категории вопроса тянет данные
 * из LMS и собирает ГОТОВЫЙ ЧЕРНОВИК ответа из строкового шаблона — БЕЗ единого
 * LLM-вызова. Возвращает null, когда фактов нет — тогда черновик не создаётся
 * (не показываем куратору пустышку и НИКОГДА не выдумываем значение).
 *
 * H3999 (волна 1 leverage-плана) вырастил три резолвера до восьми: к A/B/C
 * (ссылка, запись, расписание) добавлены статус ДЗ, остаток по оплате, состояние
 * доступа, сертификат и изменения в расписании. FINDINGS §635: FAQ-формы всего
 * ~2 % трафика ЛС — остальное вопросы студента про его собственные факты.
 *
 * Политика отправки (`send_policy`) — часть контракта, а не подсказка:
 *  - {@see self::POLICY_AUTO} — ответ в принципе может уйти студенту сам
 *    (решает ВЫЗЫВАЮЩИЙ по своему списку живых типов, см. SupportDmAutoReply);
 *  - {@see self::POLICY_DRAFT_ONLY} — только черновик куратору. Деньги (D),
 *    доступы (E) и сертификаты закреплены здесь НАВСЕГДА и в КОДЕ: правка
 *    конфига не должна уметь это снять (рулинг A1 плана leverage);
 *  - {@see self::POLICY_ESCALATE} — цифра студента расходится с расчётной;
 *    студенту не уходит ничего, куратору заводится follow-up (рулинг A1).
 */
class SupportAnswerFactResolver
{
    /** Ответ допускает автоотправку — если вызывающий счёл тип живым. */
    public const POLICY_AUTO = 'auto';

    /** Только черновик куратору. Автоотправки нет ни при каком флаге. */
    public const POLICY_DRAFT_ONLY = 'draft_only';

    /** Расхождение с заявленной цифрой: студенту молчим, куратору задача. */
    public const POLICY_ESCALATE = 'escalate';

    public const TYPE_ZOOM = 'zoom';

    public const TYPE_SCHEDULE = 'schedule';

    public const TYPE_RECORDING = 'recording';

    public const TYPE_HOMEWORK = 'homework';

    public const TYPE_BALANCE = 'balance';

    public const TYPE_ACCESS = 'access';

    public const TYPE_CERTIFICATE = 'certificate';

    public const TYPE_SCHEDULE_CHANGE = 'schedule_change';

    /**
     * Типы фактов, которым автоотправка запрещена НАВСЕГДА и в коде.
     *
     * Это и есть «забор кодом, а не конфигом» из рулинга A1: список живых типов
     * настраивает человек, но вписать сюда деньги/доступ/сертификат он не может.
     *
     * @var list<string>
     */
    public const NEVER_AUTO_TYPES = [self::TYPE_BALANCE, self::TYPE_ACCESS, self::TYPE_CERTIFICATE];

    /** Окно, в котором изменение расписания ещё считается новостью, дней. */
    private const SCHEDULE_CHANGE_WINDOW_DAYS = 14;

    /**
     * Ниже этой суммы число в сообщении студента деньгами НЕ считаем: «3 блок»,
     * «2 занятия», «10 уроков» — не денежные заявления, и эскалация по ним была
     * бы шумом. Порог сознательно грубый: цена ошибки в обе стороны — лишний или
     * недостающий follow-up куратору, студенту при этом не уходит ничего.
     */
    private const MONEY_CLAIM_FLOOR = 500.0;

    /** Допуск сравнения заявленной и расчётной суммы, рублей. */
    private const MONEY_MATCH_TOLERANCE = 1.0;

    public function __construct(
        private readonly AccessDiagnosticsService $access = new AccessDiagnosticsService,
    ) {}

    /**
     * @param  string|null  $text  текст вопроса студента; нужен там, где одной
     *                             категории мало (F — это и ДЗ, и сертификаты)
     *                             или где ответ зависит от заявленной цифры (D).
     * @return array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}|null
     */
    public function resolve(string $category, User $user, ?string $text = null): ?array
    {
        return match ($category) {
            SupportAnswerSuggestion::CATEGORY_ZOOM => $this->resolveZoom($user),
            SupportAnswerSuggestion::CATEGORY_SCHEDULE => $this->resolveScheduleArm($user, $text),
            SupportAnswerSuggestion::CATEGORY_RECORDING => $this->resolveRecording($user),
            SupportAnswerSuggestion::CATEGORY_PAYMENT => $this->resolveBalance($user, $text),
            SupportAnswerSuggestion::CATEGORY_ACCESS => $this->resolveAccess($user),
            SupportAnswerSuggestion::CATEGORY_MATERIALS => $this->resolveMaterialsArm($user, $text),
            default => null,
        };
    }

    /**
     * D (гость, H1198) — публичные тарифы видимых активных курсов, БЕЗ
     * персонализации (`Tariff::calculateFinalPriceForUser` требует `User` — у
     * анонимного посетителя его нет). Показывает «от» самого дешёвого активного
     * тарифа курса — лояльность/скидки/рассрочка уточняются у куратора. Единственная
     * категория, доступная гостю: A/B/C/E/F завязаны на личное зачисление студента
     * (группа/расписание/материалы), у гостя таких фактов нет и быть не может.
     *
     * @return array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}|null
     */
    public function resolvePublicPricing(): ?array
    {
        $courses = Course::query()
            ->where('is_visible', true)
            ->where('is_active', true)
            ->with(['tariffs' => fn ($q) => $q->where('is_active', true)->orderBy('price')])
            ->get()
            ->filter(fn (Course $course): bool => $course->tariffs->isNotEmpty())
            ->values();

        if ($courses->isEmpty()) {
            return null;
        }

        $lines = $courses->map(function (Course $course): string {
            $cheapest = $course->tariffs->first();
            $price = number_format((float) $cheapest->price, 0, ',', ' ');

            return "· «{$course->title}» — от {$price} ₽";
        })->implode("\n");

        return [
            'draft' => "Актуальные тарифы курсов (точная цена с учетом скидок/рассрочки — уточните у куратора):\n{$lines}",
            'facts' => [
                'type' => 'public_pricing',
                'courses' => $courses->map(fn (Course $course): array => [
                    'course' => $course->title,
                    'from_price' => (float) $course->tariffs->first()->price,
                ])->all(),
            ],
            'confidence' => 0.5,
            'send_policy' => self::POLICY_AUTO,
        ];
    }

    /** A — ссылка + время ближайшего занятия группы студента. */
    private function resolveZoom(User $user): ?array
    {
        $class = $this->nextClass($user);
        if ($class === null || empty($class->link)) {
            return null;
        }

        $title = trim((string) $class->title) !== '' ? "«{$class->title}» " : '';
        $draft = "Ближайшее занятие {$title}— {$this->formatWhen($class)} (МСК).\n"
            ."Ссылка для подключения: {$class->link}";

        return $this->answer(
            $draft,
            [
                'type' => self::TYPE_ZOOM,
                'schedule_id' => $class->id,
                'starts_at' => optional($class->start)->toIso8601String(),
                'title' => $class->title,
                'link' => $class->link,
            ],
            0.9,
        );
    }

    /**
     * C — расписание. Если студент спрашивает именно про ПЕРЕНОС/ОТМЕНУ и такое
     * изменение в окне действительно есть — отвечаем про него; иначе прежний
     * ответ «ближайшие занятия». Порядок именно такой: вопрос «занятие перенесли?»
     * со списком ближайших занятий формально верен и по сути мимо.
     */
    private function resolveScheduleArm(User $user, ?string $text): ?array
    {
        if ($this->mentionsScheduleChange($text)) {
            $change = $this->resolveScheduleChange($user);
            if ($change !== null) {
                return $change;
            }
        }

        return $this->resolveSchedule($user);
    }

    /** C — ближайшие занятия группы студента. */
    private function resolveSchedule(User $user): ?array
    {
        $classes = $this->upcomingClasses($user, 3);
        if ($classes->isEmpty()) {
            return null;
        }

        $lines = $classes->map(function (Schedule $class): string {
            $title = trim((string) $class->title) !== '' ? " — {$class->title}" : '';

            return "· {$this->formatWhen($class)}{$title}";
        })->implode("\n");

        return $this->answer(
            "Ближайшие занятия вашей группы (время МСК):\n{$lines}",
            [
                'type' => self::TYPE_SCHEDULE,
                'classes' => $classes->map(fn (Schedule $c): array => [
                    'schedule_id' => $c->id,
                    'starts_at' => optional($c->start)->toIso8601String(),
                    'title' => $c->title,
                ])->all(),
            ],
            0.9,
        );
    }

    /** B — ссылка на запись последнего опубликованного урока группы студента. */
    private function resolveRecording(User $user): ?array
    {
        $lesson = $this->latestRecordedLesson($user);
        if ($lesson === null) {
            return null;
        }

        $url = $lesson->youtube_url ?: ($lesson->rutube_url ?: $lesson->video_url);
        $title = trim((string) $lesson->title) !== '' ? "«{$lesson->title}» " : '';
        $draft = filled($url)
            ? "Запись урока {$title}доступна: {$url}"
            : "Запись урока {$title}доступна в личном кабинете, раздел «Уроки».";

        return $this->answer(
            $draft,
            [
                'type' => self::TYPE_RECORDING,
                'lesson_id' => $lesson->id,
                'title' => $lesson->title,
                'url' => $url ?: null,
            ],
            0.85,
        );
    }

    /**
     * F — «материалы/ДЗ/сертификаты» одной категорией накрывают два разных
     * вопроса. Категории для развода не хватает, поэтому смотрим на слова:
     * сертификат/справка — резолвер сертификата, всё остальное — статус ДЗ.
     * Без текста (старые вызовы) остаётся прежний приоритет: сперва ДЗ.
     */
    private function resolveMaterialsArm(User $user, ?string $text): ?array
    {
        if ($this->mentionsCertificate($text)) {
            return $this->resolveCertificate($user) ?? $this->resolveHomework($user);
        }

        return $this->resolveHomework($user) ?? $this->resolveCertificate($user);
    }

    /**
     * F — статус домашних работ студента по курсам его активных групп.
     *
     * `send_policy` = auto: статус ДЗ не денежный, не про доступ и уже виден
     * студенту в кабинете — ошибка стоит одного лишнего сообщения, а не
     * инцидента. Живым тип становится отдельным решением человека после
     * недели тени (рулинг V1).
     */
    private function resolveHomework(User $user): ?array
    {
        $courseIds = $this->activeCourseIds($user);
        if ($courseIds === []) {
            return null;
        }

        $submissions = HomeworkSubmission::query()
            ->where('user_id', $user->id)
            ->whereIn('course_id', $courseIds)
            ->orderByDesc('last_activity_at')
            ->orderByDesc('id')
            ->with('lesson')
            ->limit(20)
            ->get();

        if ($submissions->isEmpty()) {
            // Ни одной работы — куратору лучше пустая подсказка, чем черновик
            // «работ нет»: студент мог сдавать не через кабинет.
            return null;
        }

        /** @var HomeworkSubmission $latest */
        $latest = $submissions->first();
        $counts = $submissions->groupBy('status')->map->count();

        $draft = "Статус домашних работ в кабинете:\n"
            .'· последняя работа'
            .($latest->lesson?->title ? " по уроку «{$latest->lesson->title}»" : '')
            .' — '.$this->homeworkStatusLabel((string) $latest->status)."\n"
            .'· всего работ в кабинете: '.$submissions->count()
            .$this->homeworkBreakdown($counts);

        if ((string) $latest->status === HomeworkSubmission::STATUS_NEEDS_REVISION) {
            $draft .= "\nПо работе есть замечания — их видно в кабинете, в карточке урока.";
        }

        return $this->answer(
            $draft,
            [
                'type' => self::TYPE_HOMEWORK,
                'latest_submission_id' => $latest->id,
                'latest_status' => (string) $latest->status,
                'total' => $submissions->count(),
                'by_status' => $counts->all(),
            ],
            0.8,
        );
    }

    /**
     * D — остаток по оплате. ЧЕРНОВИК НАВСЕГДА (рулинг A1): цифра про деньги,
     * ушедшая студенту без человека, стоит дороже любой экономии времени.
     *
     * Своей денежной арифметики здесь нет и быть не должно: сумма оплаченного
     * читается из `payments`, а «сколько ещё» — это ровно
     * {@see Tariff::calculateFinalPriceForUser()} полного тарифа курса, который
     * уже учитывает скидку, зачёт депозита и зачёт при докупке. Второй расчёт
     * той же величины разошёлся бы с кассой в первый же месяц.
     */
    private function resolveBalance(User $user, ?string $text): ?array
    {
        $courses = $this->activeCourses($user);
        if ($courses->isEmpty()) {
            return null;
        }

        $rows = [];
        foreach ($courses as $course) {
            $paid = (float) Payment::query()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->paid()
                ->sum('amount');

            $ownedKeys = $this->access->ownedKeys($user, (int) $course->id);
            $full = $course->tariffs()
                ->where('is_active', true)
                ->get()
                ->first(fn (Tariff $tariff): bool => $tariff->accessKey() === 'full');

            $remaining = in_array('full', $ownedKeys, true)
                ? 0.0
                : ($full?->calculateFinalPriceForUser($user));

            $rows[] = [
                'course_id' => (int) $course->id,
                'course' => (string) $course->title,
                'paid' => round($paid, 2),
                'remaining' => $remaining === null ? null : round((float) $remaining, 2),
                'owned_keys' => array_values(array_filter($ownedKeys)),
            ];
        }

        if ($rows === []) {
            return null;
        }

        $lines = [];
        foreach ($rows as $row) {
            $paid = $this->money($row['paid']);
            if ($row['remaining'] === null) {
                $lines[] = "· «{$row['course']}»: оплачено {$paid}. Остаток уточнит куратор — полного тарифа по курсу сейчас нет.";
            } elseif ($row['remaining'] <= 0.0) {
                $lines[] = "· «{$row['course']}»: оплачено {$paid}, курс оплачен полностью.";
            } else {
                $lines[] = "· «{$row['course']}»: оплачено {$paid}, к оплате остаётся {$this->money($row['remaining'])}.";
            }
        }

        $draft = "По вашим курсам в кабинете:\n".implode("\n", $lines)
            ."\nЕсли цифра расходится с вашей — напишите, проверим по кассе.";

        $claim = $this->disputedClaim($text, $rows);

        $facts = [
            'type' => self::TYPE_BALANCE,
            'courses' => $rows,
        ];

        if ($claim !== null) {
            $facts['claimed_amount'] = $claim;

            return [
                'draft' => $draft,
                'facts' => $facts,
                'confidence' => 0.7,
                'send_policy' => self::POLICY_ESCALATE,
            ];
        }

        return [
            'draft' => $draft,
            'facts' => $facts,
            'confidence' => 0.7,
            'send_policy' => self::POLICY_DRAFT_ONLY,
        ];
    }

    /**
     * E — состояние доступа. ЧЕРНОВИК НАВСЕГДА (рулинг A1).
     *
     * Оплаченные ключи берём у {@see AccessDiagnosticsService::ownedKeys()} —
     * это уже существующий путь «оплаченные тарифы курса», и второй его
     * реализации в денежном контуре быть не должно. Открытость урока —
     * {@see Lesson::isUnlockedBy()}, тот же гейт, что и в кабинете.
     */
    private function resolveAccess(User $user): ?array
    {
        $courses = $this->activeCourses($user);
        if ($courses->isEmpty()) {
            // Нет ни одной активной группы: студент не зачислен, и отвечать
            // про его доступ нечем. Null, а не выдуманное «доступа нет».
            return null;
        }

        $rows = [];
        foreach ($courses as $course) {
            $ownedKeys = $this->access->ownedKeys($user, (int) $course->id);

            $lessons = Lesson::query()
                ->forUserGroups($user)
                ->where('course_id', $course->id)
                ->where('is_published', true)
                ->get();

            $open = $lessons->filter(fn (Lesson $lesson): bool => $this->access->isLessonAccessible(
                $lesson,
                $ownedKeys,
                $user,
                (int) $course->id,
            ))->count();

            $rows[] = [
                'course_id' => (int) $course->id,
                'course' => (string) $course->title,
                'published_lessons' => $lessons->count(),
                'open_lessons' => $open,
                'owned_keys' => array_values(array_filter($ownedKeys)),
            ];
        }

        $lines = [];
        foreach ($rows as $row) {
            if ($row['published_lessons'] === 0) {
                $lines[] = "· «{$row['course']}»: опубликованных уроков пока нет.";

                continue;
            }

            $lines[] = "· «{$row['course']}»: открыто {$row['open_lessons']} из {$row['published_lessons']} опубликованных уроков"
                .($row['owned_keys'] === [] ? ' (оплаченных тарифов по курсу в кабинете нет).' : '.');
        }

        $draft = "Доступ по вашим курсам в кабинете:\n".implode("\n", $lines)
            ."\nЕсли нужный урок закрыт, а оплата была — напишите, проверим.";

        return [
            'draft' => $draft,
            'facts' => ['type' => self::TYPE_ACCESS, 'courses' => $rows],
            'confidence' => 0.75,
            'send_policy' => self::POLICY_DRAFT_ONLY,
        ];
    }

    /**
     * F — сертификат. ЧЕРНОВИК НАВСЕГДА: неверное утверждение про сертификат —
     * это инцидент поддержки, а не опечатка.
     */
    private function resolveCertificate(User $user): ?array
    {
        $certificates = Certificate::query()
            ->where('user_id', $user->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        if ($certificates->isEmpty()) {
            return null;
        }

        /** @var Certificate $latest */
        $latest = $certificates->first();
        $activeGroupIds = $user->activeGroups->pluck('id')->all();
        $foreignGroup = $latest->group_id !== null && ! in_array((int) $latest->group_id, array_map('intval', $activeGroupIds), true);

        // `certificates.issued_at` — колонка типа date, NOT NULL и БЕЗ каста в
        // модели: приходит строкой, и «даты нет» не бывает вовсе (модель на
        // creating подставляет now()). Поэтому Carbon::parse, а не formatDate от
        // объекта, и ветка «ещё не выдан» опирается на дату В БУДУЩЕМ — это
        // единственное «ещё не выдан», которое схема умеет выразить.
        $issuedAt = CarbonImmutable::parse((string) $latest->issued_at);
        $pending = $issuedAt->isAfter(CarbonImmutable::now());

        $label = $latest->documentLabel();
        $course = trim((string) $latest->displayCourseTitle());

        if ($pending) {
            $draft = "{$label} по курсу «{$course}» оформлен, дата выдачи — {$this->formatDate($issuedAt)}."
                .' До этой даты документ в кабинете не появится.';
        } else {
            $draft = "{$label} по курсу «{$course}» выдан {$this->formatDate($issuedAt)}"
                .($latest->number ? ", номер {$latest->number}" : '')
                .'. Документ доступен в личном кабинете.';
        }

        if ($foreignGroup) {
            $draft .= "\nОбратите внимание: документ оформлен по другой группе — уточните у куратора, тот ли это курс.";
        }

        return [
            'draft' => $draft,
            'facts' => [
                'type' => self::TYPE_CERTIFICATE,
                'certificate_id' => $latest->id,
                'issued_at' => $issuedAt->toDateString(),
                'pending' => $pending,
                'group_id' => $latest->group_id === null ? null : (int) $latest->group_id,
                'foreign_group' => $foreignGroup,
                'total' => $certificates->count(),
            ],
            'confidence' => 0.8,
            'send_policy' => self::POLICY_DRAFT_ONLY,
        ];
    }

    /**
     * C — изменения расписания группы за окно: перенос, отмена, новое занятие.
     *
     * Перенос от нового занятия отличаем ПО ТАБЛИЦЕ, а не по догадке: строка,
     * СОЗДАННАЯ внутри окна, — новое занятие; строка, созданная раньше окна, но
     * ОБНОВЛЁННАЯ внутри него, — перенос. Отменённое занятие — soft-deleted
     * (H3790 распускает группы именно так). Прежнего времени в базе нет, поэтому
     * про перенос говорим «время изменилось, теперь …», а не выдуманное «с … на …».
     */
    private function resolveScheduleChange(User $user): ?array
    {
        $groupIds = $user->activeGroups->pluck('id')->all();
        if ($groupIds === []) {
            return null;
        }

        $since = now()->subDays(self::SCHEDULE_CHANGE_WINDOW_DAYS);

        /** @var Collection<int, Schedule> $touched */
        $touched = Schedule::query()
            ->withTrashed()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('start')
            ->where('start', '>=', now())
            ->where(fn ($q) => $q->where('updated_at', '>=', $since)->orWhere('deleted_at', '>=', $since))
            ->orderBy('start')
            ->limit(5)
            ->get();

        $changes = [];
        foreach ($touched as $class) {
            $kind = $this->scheduleChangeKind($class, $since);
            if ($kind === null) {
                continue;
            }

            $changes[] = [
                'schedule_id' => $class->id,
                'kind' => $kind,
                'starts_at' => optional($class->start)->toIso8601String(),
                'title' => $class->title,
            ];
        }

        if ($changes === []) {
            return null;
        }

        $lines = [];
        foreach ($changes as $change) {
            /** @var Schedule $class */
            $class = $touched->firstWhere('id', $change['schedule_id']);
            $title = trim((string) $change['title']) !== '' ? " «{$change['title']}»" : '';
            $when = $this->formatWhen($class);

            $lines[] = match ($change['kind']) {
                'cancelled' => "· занятие{$title} {$when} (МСК) отменено",
                'moved' => "· занятие{$title} перенесено — теперь {$when} (МСК)",
                default => "· добавлено занятие{$title} — {$when} (МСК)",
            };
        }

        return $this->answer(
            "Изменения в расписании вашей группы:\n".implode("\n", $lines),
            [
                'type' => self::TYPE_SCHEDULE_CHANGE,
                'window_days' => self::SCHEDULE_CHANGE_WINDOW_DAYS,
                'changes' => $changes,
            ],
            0.85,
        );
    }

    /** @return 'cancelled'|'moved'|'added'|null */
    private function scheduleChangeKind(Schedule $class, \DateTimeInterface $since): ?string
    {
        if ($class->deleted_at !== null) {
            return $class->deleted_at->gte($since) ? 'cancelled' : null;
        }

        if ($class->created_at !== null && $class->created_at->gte($since)) {
            return 'added';
        }

        // Обновление без смены времени (проставили ссылку, отметку напоминания)
        // переносом не является — требуем расхождения created/updated И того,
        // что обновление попало в окно.
        if ($class->updated_at !== null
            && $class->updated_at->gte($since)
            && $class->created_at !== null
            && $class->updated_at->gt($class->created_at->addSecond())
        ) {
            return 'moved';
        }

        return null;
    }

    /**
     * Заявленная студентом сумма, которая НЕ сходится ни с одной расчётной
     * цифрой по его курсам. null — расхождения нет (или денежных чисел в
     * сообщении нет вовсе).
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function disputedClaim(?string $text, array $rows): ?float
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $known = [];
        foreach ($rows as $row) {
            $known[] = (float) $row['paid'];
            if ($row['remaining'] !== null) {
                $known[] = (float) $row['remaining'];
            }
        }

        foreach ($this->moneyClaims($text) as $claim) {
            $matches = false;
            foreach ($known as $value) {
                if (abs($value - $claim) <= self::MONEY_MATCH_TOLERANCE) {
                    $matches = true;
                    break;
                }
            }

            if (! $matches) {
                return $claim;
            }
        }

        return null;
    }

    /**
     * Денежные числа из текста: «15000», «15 000», «15.000», «15 000 ₽».
     *
     * @return list<float>
     */
    private function moneyClaims(string $text): array
    {
        if (preg_match_all('/\d[\d\s\x{00A0}.,]*/u', $text, $matches) === false) {
            return [];
        }

        $claims = [];
        foreach ($matches[0] as $raw) {
            $digits = preg_replace('/[^\d]/u', '', $raw);
            if ($digits === '' || $digits === null) {
                continue;
            }

            $value = (float) $digits;
            if ($value >= self::MONEY_CLAIM_FLOOR) {
                $claims[] = $value;
            }
        }

        return $claims;
    }

    private function mentionsCertificate(?string $text): bool
    {
        return $this->mentionsAny($text, ['сертификат', 'справк', 'диплом', 'удостоверен']);
    }

    private function mentionsScheduleChange(?string $text): bool
    {
        return $this->mentionsAny($text, ['перенес', 'перенёс', 'перенос', 'отмен', 'сдвин', 'изменил', 'изменен', 'изменён']);
    }

    /** @param list<string> $needles */
    private function mentionsAny(?string $text, array $needles): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $haystack = mb_strtolower($text);
        foreach ($needles as $needle) {
            if (mb_strpos($haystack, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{draft: string, facts: array<string, mixed>, confidence: float, send_policy: string}
     */
    private function answer(string $draft, array $facts, float $confidence): array
    {
        return [
            'draft' => $draft,
            'facts' => $facts,
            'confidence' => $confidence,
            'send_policy' => self::POLICY_AUTO,
        ];
    }

    private function homeworkStatusLabel(string $status): string
    {
        return match ($status) {
            HomeworkSubmission::STATUS_DRAFT => 'черновик, ещё не отправлена на проверку',
            HomeworkSubmission::STATUS_SUBMITTED => 'отправлена на проверку',
            HomeworkSubmission::STATUS_NEEDS_REVISION => 'возвращена на доработку',
            HomeworkSubmission::STATUS_ACCEPTED => 'принята',
            default => $status,
        };
    }

    /** @param Collection<string, int> $counts */
    private function homeworkBreakdown(Collection $counts): string
    {
        $parts = [];
        foreach ([
            HomeworkSubmission::STATUS_ACCEPTED => 'принято',
            HomeworkSubmission::STATUS_SUBMITTED => 'на проверке',
            HomeworkSubmission::STATUS_NEEDS_REVISION => 'на доработке',
            HomeworkSubmission::STATUS_DRAFT => 'в черновиках',
        ] as $status => $label) {
            $count = (int) ($counts[$status] ?? 0);
            if ($count > 0) {
                $parts[] = "{$label} {$count}";
            }
        }

        return $parts === [] ? '' : ' ('.implode(', ', $parts).')';
    }

    private function money(float $amount): string
    {
        return number_format($amount, 0, ',', ' ').' ₽';
    }

    /** @return list<int> */
    private function activeCourseIds(User $user): array
    {
        return $this->activeCourses($user)->pluck('id')->map('intval')->values()->all();
    }

    /** @return Collection<int, Course> */
    private function activeCourses(User $user): Collection
    {
        return $user->activeGroups
            ->flatMap(fn ($group) => $group->courses)
            ->unique('id')
            ->values();
    }

    private function nextClass(User $user): ?Schedule
    {
        $groupIds = $user->activeGroups->pluck('id')->all();
        if ($groupIds === []) {
            return null;
        }

        return Schedule::query()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('start')
            ->where('start', '>=', now())
            ->orderBy('start')
            ->first();
    }

    /** @return Collection<int, Schedule> */
    private function upcomingClasses(User $user, int $limit): Collection
    {
        $groupIds = $user->activeGroups->pluck('id')->all();
        if ($groupIds === []) {
            return collect();
        }

        return Schedule::query()
            ->whereIn('group_id', $groupIds)
            ->whereNotNull('start')
            ->where('start', '>=', now())
            ->orderBy('start')
            ->limit($limit)
            ->get();
    }

    private function latestRecordedLesson(User $user): ?Lesson
    {
        return Lesson::query()
            ->forUserGroups($user)
            ->where('is_published', true)
            ->withVideo()
            ->orderByDesc('lesson_date')
            ->orderByDesc('sort_order')
            ->first();
    }

    private function formatWhen(Schedule $class): string
    {
        return optional($class->start)->format('d.m.Y H:i') ?? '';
    }

    private function formatDate(\DateTimeInterface $date): string
    {
        return $date->format('d.m.Y');
    }
}
