<?php

namespace App\Http\Controllers;

use App\Jobs\TrackLessonViewJob;
use App\Models\ActivityEvent;
use App\Models\Announcement;
use App\Models\Course;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\LessonView;
use App\Models\Payment;
use App\Models\PranaPerk;
use App\Models\PranaRedemption;
use App\Models\Schedule;
use App\Models\SubscriberMagnet;
use App\Services\Activity\CabinetTelemetry;
use App\Services\Cabinet\GrammarLadder;
use App\Services\Cabinet\RecordingsCatalog;
use App\Services\Cabinet\RecoveryState;
use App\Services\Cabinet\RecoveryStateResolver;
use App\Services\CertificateService;
use App\Services\CourseMaterialsArchiver;
use App\Services\DebtPaymentResolver;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use App\Services\StudentDebtsService;
use App\Support\Badges;
use App\Support\KinescopePilot;
use App\Support\OnboardingChecklist;
use App\Support\PranaLeaderboard;
use App\Support\TranscriptParser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class StudentController extends Controller
{
    /**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Получение купленных тарифов ===
     * Железобетонный метод проверки доступов строго по ID КУРСА
     */
    private function getUserUnlockedTariffs($userId, $courseSlug): array
    {
        // 1. Находим ID курса по его slug
        $courseId = Course::where('slug', $courseSlug)->value('id');

        // Если курс не найден, возвращаем пустой массив (нет доступов)
        if (! $courseId) {
            return [];
        }

        // 2. Ищем оплаченные тарифы строго по ID КУРСА, а не лендинга.
        //    Учитываем оба статуса оплаты ('paid' и 'success') — иначе урок
        //    остаётся закрытым для success-платежей, хотя группа уже выдана.
        return Payment::where('user_id', $userId)
            ->where('course_id', $courseId)
            ->paid()
            ->pluck('tariff')
            ->toArray();
    }

    /**
     * Страница расписания (Timeline)
     */
    public function calendar()
    {
        $user = auth()->user();
        $groupIds = $user->groups->pluck('id');

        $upcomingEvents = Schedule::with(['course', 'group'])
            ->where(function ($query) use ($groupIds) {
                $query->whereIn('group_id', $groupIds)
                    ->orWhereNull('group_id');
            })
            ->where(function ($query) {
                // Карточка живёт, пока занятие не закончилось: по end, а для
                // записей без end — DEFAULT_DURATION_HOURS от старта (как в
                // Schedule::isLive() и в самой карточке). Иначе карточка со
                // ссылкой исчезала ровно в момент начала идущего занятия.
                $query->where('end', '>=', now())
                    ->orWhere(function ($q) {
                        $q->whereNull('end')
                            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS));
                    });
            })
            ->orderBy('start', 'asc')
            ->get();

        $groupedEvents = $upcomingEvents->groupBy(function ($event) {
            if ($event->start->isToday()) {
                return 'Сегодня';
            }
            if ($event->start->isTomorrow()) {
                return 'Завтра';
            }

            return $event->start->translatedFormat('d F, l');
        });

        $feedToken = $user->calendarFeedToken()->token;
        $feedUrl = route('student.calendar.feed', ['user' => $user->id, 'token' => $feedToken]);
        $webcalUrl = preg_replace('~^https?://~', 'webcal://', $feedUrl);

        return view('student.calendar', compact('groupedEvents', 'feedUrl', 'webcalUrl'));
    }

    /**
     * Отвязка мессенджера от аккаунта (кнопка «Отвязать» в кабинете).
     *
     * Обнуляем id — кабинет снова покажет «Подключить», а исходящие уведомления
     * (User::sendTelegramMessage / sendVkMessage) перестанут уходить (некуда).
     * Сам чат с ботом в мессенджере при этом не закрывается — это нативный Stop.
     */
    public function disconnectMessenger(Request $request, string $channel)
    {
        $user = $request->user();

        if ($channel === 'telegram') {
            $user->update(['telegram_id' => null, 'telegram_auth_token' => null]);
        } else { // 'vk' — единственный другой вариант (ограничено в роуте whereIn)
            $user->update(['vk_id' => null, 'vk_auth_token' => null]);
        }

        return back()->with('bot_status', 'Бот отвязан — уведомления по учёбе больше не приходят. Подключить заново можно в любой момент.');
    }

    /**
     * Главная панель (Мои курсы)
     */
    public function dashboard()
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // БЫЛО: where('is_visible', true) — ломало доступ при скрытии с витрины
        // СТАЛО: фильтруем по is_active (видимость в ЛК)
        // Уроки тянем упорядоченными (sort_order→created_at) с group_id/title —
        // нужно и для прогресс-бара, и для «следующего урока» в карточке (без N+1).
        $courses = Course::where('is_active', true)
            ->whereHas('groups', function ($query) use ($userGroupIds) {
                $query->whereIn('groups.id', $userGroupIds);
            })
            ->with(['lessons' => function ($query) {
                $query->select('id', 'course_id', 'title', 'group_id', 'sort_order', 'created_at')
                    ->orderBy('sort_order')
                    ->orderBy('created_at');
            }])
            ->get();

        // «Следующий урок» для карточки курса: первый по порядку доступный студенту
        // (по группе) и ещё не пройденный урок. null = всё пройдено либо доступных нет.
        $completedLessonIds = $user->completedLessons->pluck('id')->all();
        $nextLessonByCourseId = $courses->mapWithKeys(fn (Course $course) => [
            $course->id => $course->lessons->first(
                fn (Lesson $lesson) => $lesson->isVisibleToGroupsOf($user)
                    && ! in_array($lesson->id, $completedLessonIds, true)
            ),
        ]);

        $certificates = $user->certificates()
            ->with('course')
            ->orderBy('created_at', 'desc')
            ->get();

        $pranaTransactions = $user->pranaTransactions()->limit(30)->get();
        // Сами числа наград админ редактирует в админке → читаем через PranaSettings,
        // иначе панель «Как заработать прану» расходится с тем, что реально начисляет
        // PranaService::award. Лейблы (reasons) не редактируются — оставляем config.
        $pranaRewards = PranaSettings::allRewards();
        $pranaReasons = config('prana.reasons', []);

        // Таблица лидеров по накопленной пране (геймификация).
        $pranaLeaderboard = PranaLeaderboard::rows(10, $user->id);
        // Бейджи (достижения) — вычисляются из сигналов прогресса/праны.
        $badges = Badges::for($user);
        // Магазин праны (spend-sink): активные перки + последние покупки студента.
        $pranaPerks = PranaPerk::shownInShop()->get();
        $pranaRedemptions = PranaRedemption::where('user_id', $user->id)
            ->latest()->limit(5)->get();

        $debtsService = app(StudentDebtsService::class);
        $debts = $debtsService->forUser($user);
        $debtsByCourseId = $debts->keyBy('course_id');

        // «Оплачено до»: положительная сводка (блок/дата) для курсов без долга —
        // отвечает на «сколько оплатил и до какого момента я покрыт».
        $paidUntilByCourseId = $debtsService->paidUntilForUser($user, $courses->pluck('id'));

        // Варианты самостоятельной оплаты долга (self-service): тариф-ссылки для
        // «не продлил», следующий платёж / погасить всё — для рассрочки.
        $debtPayResolver = app(DebtPaymentResolver::class);
        $debtPayOptions = $debts->mapWithKeys(fn ($d) => [$d->course_id => $debtPayResolver->optionsFor($d, $user)]);

        // Отдельно открытые уроки (например, оплаченное пробное занятие): курсы, к
        // которым нет полного доступа по группам, но есть персональный grant на урок.
        $trialLessons = LessonAccessGrant::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereNotIn('course_id', $courses->pluck('id')->all())
            ->with(['lesson:id,course_id,title,lesson_date', 'course:id,slug,title'])
            ->get()
            ->filter(fn (LessonAccessGrant $g) => $g->lesson && $g->course)
            ->values();

        // Чеклист первых шагов (P0-онбординг). Карточку показываем, пока не все
        // шаги выполнены — см. partial onboarding-checklist.
        $onboarding = OnboardingChecklist::for($user);

        // Ответы преподавателя, требующие действия студента (работа возвращена на
        // доработку) — чтобы он узнал сразу в кабинете, а не только из письма.
        $homeworkAlerts = $user->homeworkSubmissions()
            ->where('status', HomeworkSubmission::STATUS_NEEDS_REVISION)
            ->with(['lesson:id,course_id,title', 'course:id,slug,title'])
            ->latest('reviewed_at')
            ->get()
            ->filter(fn ($s) => $s->lesson && $s->course)
            ->values();
        $continueLearningAction = $this->buildContinueLearningAction(
            $courses,
            $nextLessonByCourseId,
            $completedLessonIds,
            $debts,
            $debtPayOptions,
            $trialLessons,
            $homeworkAlerts,
        );

        // «Полка подписчика» (H324): бесплатные магниты для подписчиков рассылки.
        // Пустая коллекция, когда флаг OFF или пользователь не подписчик — partial
        // сам ничего не рендерит. Платного доступа не касается.
        $subscriberMagnets = (config('features.newsletter_subscribe') && $user->isNewsletterSubscriber())
            ? SubscriberMagnet::active()->get()
            : collect();

        // R29.2 / H1481: recovery-state predicate (declined payment / expired
        // promise). When cabinet_hybrid is OFF the resolver still runs so
        // telemetry mode is truthful once the flag flips without a second deploy.
        $recovery = app(RecoveryStateResolver::class)->resolve($user);
        $suppressOffers = $recovery->suppressOffers();

        // Baseline-телеметрия ремейка (H962, спека §4). mode: normal|recovery.
        app(CabinetTelemetry::class)->emit(
            user: $user,
            event: ActivityEvent::CABINET_HOME_VIEW,
            data: [
                'mode' => $recovery->mode(),
                'debts' => $debts->count(),
                'reason' => $recovery->reason,
            ],
            request: request(),
        );

        $viewData = compact(
            'courses',
            'nextLessonByCourseId',
            'certificates',
            'pranaTransactions',
            'pranaRewards',
            'pranaLeaderboard',
            'badges',
            'pranaPerks',
            'pranaRedemptions',
            'pranaReasons',
            'debts',
            'debtsByCourseId',
            'paidUntilByCourseId',
            'debtPayOptions',
            'trialLessons',
            'onboarding',
            'homeworkAlerts',
            'subscriberMagnets',
            'continueLearningAction',
            'recovery',
            'suppressOffers',
        );

        // Phase 1 hybrid chassis (H1481): job-named shell + today band + recovery.
        // Flag OFF → byte-stable legacy dashboard (recovery vars unused there).
        if (config('features.cabinet_hybrid')) {
            $nearestLive = $this->nearestLiveForUser($user);
            $viewData['nearestLive'] = $nearestLive;
            $viewData['todayBand'] = $this->buildTodayBand(
                $continueLearningAction,
                $nearestLive,
                $homeworkAlerts,
                $recovery,
            );

            return view('student.hybrid.home', $viewData);
        }

        return view('student.dashboard', $viewData);
    }

    /**
     * «Записи» (hybrid job-nav). Phase 2: ownership shelves + rail + offer (H1572).
     */
    public function library()
    {
        abort_unless((bool) config('features.cabinet_hybrid'), 404);

        $user = auth()->user();
        $recovery = app(RecoveryStateResolver::class)->resolve($user);
        $catalog = app(RecordingsCatalog::class)->forUser($user);
        $suppressOffers = $recovery->suppressOffers();

        // Server-side shelf impressions (one event per non-empty shelf).
        $telemetry = app(CabinetTelemetry::class);
        foreach ($catalog['shelves'] as $shelf => $cards) {
            if ($cards->isNotEmpty()) {
                $telemetry->emit(
                    user: $user,
                    event: ActivityEvent::LIBRARY_SHELF_VIEW,
                    data: ['shelf' => $shelf, 'n' => $cards->count()],
                    request: request(),
                );
            }
        }

        $ownershipOffer = $suppressOffers ? null : $catalog['ownership_offer'];
        if ($ownershipOffer) {
            $telemetry->emit(
                user: $user,
                event: ActivityEvent::OFFER_IMPRESSION,
                data: [
                    'kind' => 'ownership',
                    'course_id' => $ownershipOffer->course->id,
                    'eligibility' => 'progress_gated',
                    'state' => $recovery->mode(),
                ],
                request: request(),
            );
        }

        return view('student.hybrid.library', [
            'recovery' => $recovery,
            'suppressOffers' => $suppressOffers,
            'shelves' => $catalog['shelves'],
            'ownershipOffer' => $ownershipOffer,
            'lapses' => $catalog['lapses'],
        ]);
    }

    /**
     * «Прогресс» (hybrid job-nav). Phase 3: grammar ladder + lighting (H1573).
     */
    public function progress()
    {
        abort_unless((bool) config('features.cabinet_hybrid'), 404);

        $user = auth()->user();
        $certificates = $user->certificates()->with('course')->orderByDesc('created_at')->get();
        $recovery = app(RecoveryStateResolver::class)->resolve($user);
        $suppressOffers = $recovery->suppressOffers();
        $ladder = app(GrammarLadder::class)->forUser($user);
        $ladderOffer = $suppressOffers ? null : $ladder['ladder_offer'];

        $telemetry = app(CabinetTelemetry::class);
        $telemetry->emit(
            user: $user,
            event: ActivityEvent::PATH_STATION_VIEW,
            data: [
                'n' => count($ladder['stations']),
                'lit' => count($ladder['lit_keys']),
                'mode' => $recovery->mode(),
            ],
            request: request(),
        );
        foreach ($ladder['lit_keys'] as $key) {
            $telemetry->emit(
                user: $user,
                event: ActivityEvent::PATH_STATION_LIT_IMPRESSION,
                data: ['station' => $key],
                request: request(),
            );
        }
        if ($ladderOffer) {
            $telemetry->emit(
                user: $user,
                event: ActivityEvent::OFFER_IMPRESSION,
                data: [
                    'kind' => 'ladder',
                    'to' => $ladderOffer->to_key,
                    'state' => $recovery->mode(),
                ],
                request: request(),
            );
        }

        return view('student.hybrid.progress', [
            'certificates' => $certificates,
            'recovery' => $recovery,
            'suppressOffers' => $suppressOffers,
            'stations' => $ladder['stations'],
            'ladderOffer' => $ladderOffer,
            'litKeys' => $ladder['lit_keys'],
        ]);
    }

    /**
     * «Оплата и доступ» (hybrid job-nav). Feeds R29.2 recovery CTA.
     */
    public function access()
    {
        abort_unless((bool) config('features.cabinet_hybrid'), 404);

        $user = auth()->user();
        $debtsService = app(StudentDebtsService::class);
        $debts = $debtsService->forUser($user);
        $debtPayResolver = app(DebtPaymentResolver::class);
        $debtPayOptions = $debts->mapWithKeys(
            fn ($d) => [$d->course_id => $debtPayResolver->optionsFor($d, $user)]
        );
        $recovery = app(RecoveryStateResolver::class)->resolve($user);

        // Access page is always offer-suppressed (R2 / B v2 access.html).
        return view('student.hybrid.access', [
            'debts' => $debts,
            'debtPayOptions' => $debtPayOptions,
            'recovery' => $recovery,
            'suppressOffers' => true,
        ]);
    }

    /**
     * R29.1 «Сегодня» composite band: continue + nearest live + homework rework
     * (third element only when homework was returned for revision).
     *
     * @param  array<string, mixed>  $continueLearningAction
     * @param  Collection<int, HomeworkSubmission>  $homeworkAlerts
     * @return array{continue: ?array, live: ?array, homework: ?array}
     */
    private function buildTodayBand(
        array $continueLearningAction,
        ?array $nearestLive,
        $homeworkAlerts,
        RecoveryState $recovery,
    ): array {
        // In recovery, the banner owns the primary CTA — continue band still
        // surfaces owned learning, but never a debt-upsell kind.
        $continue = $continueLearningAction;
        if ($recovery->active && ($continue['kind'] ?? null) === 'debt') {
            $continue = null;
        }

        $homework = null;
        $hw = $homeworkAlerts->first();
        if ($hw) {
            $homework = [
                'kind' => 'homework_rework',
                'title' => 'Доработать домашнее',
                'body' => $hw->course->title ?? 'Курс',
                'meta' => $hw->lesson->title ?? null,
                'cta' => [
                    'label' => 'Открыть урок',
                    'url' => route('student.lesson', [$hw->course->slug, $hw->lesson->id]),
                    'method' => 'GET',
                ],
            ];
        }

        return [
            'continue' => $continue,
            'live' => $nearestLive,
            'homework' => $homework,
        ];
    }

    /**
     * Nearest upcoming live class the student can attend (owned group membership).
     *
     * @return array{title: string, meta: string, start: Carbon, url: string}|null
     */
    private function nearestLiveForUser($user): ?array
    {
        $groupIds = $user->groups->pluck('id');
        if ($groupIds->isEmpty()) {
            return null;
        }

        $event = Schedule::with(['course', 'group'])
            ->whereIn('group_id', $groupIds)
            ->where(function ($query) {
                $query->where('end', '>=', now())
                    ->orWhere(function ($q) {
                        $q->whereNull('end')
                            ->where('start', '>=', now()->subHours(Schedule::DEFAULT_DURATION_HOURS));
                    });
            })
            ->orderBy('start')
            ->first();

        if (! $event) {
            return null;
        }

        $when = $event->start->isToday()
            ? 'Сегодня, '.$event->start->format('H:i')
            : $event->start->translatedFormat('d F, H:i');

        return [
            'title' => $event->title ?: ($event->course->title ?? 'Живое занятие'),
            'meta' => $when.($event->group ? ' · '.$event->group->name : ''),
            'start' => $event->start,
            'url' => route('student.calendar'),
        ];
    }

    /**
     * Верхний блок кабинета: одно главное действие, без изменения доступов/оплат.
     */
    private function buildContinueLearningAction(
        $courses,
        $nextLessonByCourseId,
        array $completedLessonIds,
        $debts,
        $debtPayOptions,
        $trialLessons,
        $homeworkAlerts,
    ): array {
        foreach ($debts as $debt) {
            $opts = $debtPayOptions[$debt->course_id] ?? null;
            $cta = is_array($opts) ? $this->continueLearningDebtCta($opts) : null;

            if ($cta !== null) {
                return [
                    'kind' => 'debt',
                    'title' => 'Нужно действие по оплате',
                    'body' => $debt->course->title ?? 'Курс',
                    'meta' => $this->continueLearningDebtMeta($debt),
                    'cta' => $cta,
                ];
            }
        }

        $homework = $homeworkAlerts->first();
        if ($homework) {
            return [
                'kind' => 'homework',
                'title' => 'Домашнее задание вернулось на доработку',
                'body' => $homework->course->title ?? 'Курс',
                'meta' => $homework->lesson->title ?? null,
                'cta' => [
                    'label' => 'Открыть урок',
                    'url' => route('student.lesson', [$homework->course->slug, $homework->lesson->id]),
                    'method' => 'GET',
                ],
            ];
        }

        $trial = $trialLessons->first();
        if ($trial) {
            return [
                'kind' => 'trial',
                'title' => 'Открыто пробное занятие',
                'body' => $trial->course->title,
                'meta' => $trial->lesson->title,
                'cta' => [
                    'label' => 'Перейти к занятию',
                    'url' => route('student.lesson', [$trial->course->slug, $trial->lesson->id]),
                    'method' => 'GET',
                ],
            ];
        }

        foreach ($courses as $course) {
            $lesson = $nextLessonByCourseId->get($course->id);
            if (! $lesson) {
                continue;
            }

            $totalLessons = $course->lessons->count();
            $completedLessons = $course->lessons
                ->filter(fn (Lesson $courseLesson) => in_array($courseLesson->id, $completedLessonIds, true))
                ->count();
            $percent = $totalLessons > 0 ? (int) round(($completedLessons / $totalLessons) * 100) : 0;

            return [
                'kind' => 'lesson',
                'title' => 'Продолжить обучение',
                'body' => $course->title,
                'meta' => $lesson->title,
                'progress' => $percent,
                'cta' => [
                    'label' => $percent > 0 ? 'Продолжить' : 'Начать обучение',
                    'url' => route('student.lesson', [$course->slug, $lesson->id]),
                    'method' => 'GET',
                ],
            ];
        }

        $firstCourse = $courses->first();
        if ($firstCourse) {
            return [
                'kind' => 'completed',
                'title' => 'Все доступные уроки пройдены',
                'body' => $firstCourse->title,
                'meta' => 'Можно вернуться к материалам курса.',
                'cta' => [
                    'label' => 'Открыть курс',
                    'url' => route('student.course', $firstCourse->slug),
                    'method' => 'GET',
                ],
            ];
        }

        return [
            'kind' => 'empty',
            'title' => 'Пока нет доступных курсов',
            'body' => 'Посмотрите каталог и выберите подходящий курс.',
            'meta' => null,
            'cta' => [
                'label' => 'Перейти в каталог',
                'url' => route('shop.index'),
                'method' => 'GET',
            ],
        ];
    }

    private function continueLearningDebtCta(array $opts): ?array
    {
        if (($opts['type'] ?? null) === 'arrangement') {
            if (! empty($opts['next'])) {
                return [
                    'label' => 'Оплатить следующий взнос',
                    'url' => $opts['next']['url'],
                    'method' => 'POST',
                ];
            }

            if (! empty($opts['whole'])) {
                return [
                    'label' => 'Погасить всё',
                    'url' => $opts['whole']['url'],
                    'method' => 'POST',
                ];
            }
        }

        if (($opts['type'] ?? null) === 'tariff') {
            if (! empty($opts['full'])) {
                return [
                    'label' => 'Оплатить курс',
                    'url' => $opts['full']['url'],
                    'method' => 'GET',
                ];
            }

            if (! empty($opts['bundle'])) {
                return [
                    'label' => 'Оплатить блоки',
                    'url' => $opts['bundle']['url'],
                    'method' => 'POST',
                ];
            }

            if (! empty($opts['blocks'][0])) {
                return [
                    'label' => 'Оплатить блок №'.$opts['blocks'][0]['number'],
                    'url' => $opts['blocks'][0]['url'],
                    'method' => 'GET',
                ];
            }
        }

        if (($opts['type'] ?? null) !== 'none') {
            return [
                'label' => 'Открыть долги',
                'url' => '#debts',
                'method' => 'TAB',
            ];
        }

        return null;
    }

    private function continueLearningDebtMeta(object $debt): ?string
    {
        $parts = [];

        // Договорённость/рассрочка: сумма и дата живут на promise, а не в
        // tariff-debt_amount (часто пустой при чистой «договорённости» без
        // block-долга) — иначе CTA «Оплатить следующий взнос» без суммы.
        if (! empty($debt->has_arrangement) && $debt->promise) {
            if ($debt->promise->promised_at) {
                $parts[] = 'до '.$debt->promise->promised_at->format('d.m.Y');
            }
            $amount = $debt->promise->amount !== null
                ? (float) $debt->promise->amount
                : (! empty($debt->plan_remaining) ? (float) $debt->plan_remaining : null);
            if ($amount !== null && $amount > 0) {
                $parts[] = number_format($amount, 0, '.', ' ').' ₽';
            }

            return $parts ? implode(' · ', $parts) : null;
        }

        if (! empty($debt->debt_label)) {
            $parts[] = $debt->debt_label;
        }
        if (! empty($debt->debt_amount)) {
            $parts[] = ($debt->debt_amount_approximate ? '≈ ' : '').number_format((float) $debt->debt_amount, 0, '.', ' ').' ₽';
        }
        if (empty($parts) && ! empty($debt->plan_remaining)) {
            $parts[] = number_format((float) $debt->plan_remaining, 0, '.', ' ').' ₽ осталось по графику';
        }

        return $parts ? implode(' · ', $parts) : null;
    }

    /**
     * Просмотр содержания курса (список уроков)
     */
    public function showCourse($slug)
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // БЫЛО: where('is_visible', true)
        // СТАЛО: where('is_active', true)
        $course = Course::where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('groups', function ($query) use ($userGroupIds) {
                $query->whereIn('groups.id', $userGroupIds);
            })
            ->firstOrFail();

        $lessons = $course->lessons()->forUserGroups($user)->orderBy('sort_order')->orderBy('created_at')->get();
        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $slug);
        $grantedLessonIds = LessonAccessGrant::userGrantedLessonIds($user, (int) $course->id);

        if (config('features.cabinet_hybrid')) {
            $recovery = app(RecoveryStateResolver::class)->resolve($user);
            $completedLessonIds = $user->completedLessons->pluck('id')->all();
            // R29.8: block start/end landmarks — orientation only, never pay deadlines.
            $landmarks = app(GrammarLadder::class)->landmarksForCourse($course);

            return view('student.hybrid.course', [
                'course' => $course,
                'lessons' => $lessons,
                'unlockedTariffs' => $unlockedTariffs,
                'grantedLessonIds' => $grantedLessonIds,
                'completedLessonIds' => $completedLessonIds,
                'recovery' => $recovery,
                'suppressOffers' => $recovery->suppressOffers(),
                'landmarks' => $landmarks,
            ]);
        }

        return view('student.course', compact('course', 'lessons', 'unlockedTariffs', 'grantedLessonIds'));
    }

    /**
     * Просмотр конкретного урока (Плеер + Навигация)
     */
    public function showLesson($courseSlug, $lessonId, PranaService $prana)
    {
        $user = auth()->user();
        $course = Course::where('slug', $courseSlug)->firstOrFail();
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        // Разовый доступ к конкретному уроку (например, оплаченное пробное занятие) —
        // обход и блок/full гейта, и группового: явный grant на этот урок главнее.
        $hasLessonGrant = LessonAccessGrant::userCanWatch($user, $lesson);

        // Урок другой группы курса (курс разнесён на 2 потока) — не показываем,
        // если только нет персонального гранта именно на этот урок.
        if (! $hasLessonGrant && ! $lesson->isVisibleToGroupsOf($user)) {
            return redirect()->route('student.course', $course->slug)
                ->with('error', 'Этот урок относится к другой группе курса.');
        }

        // --- БЛОК ЗАЩИТЫ ДОСТУПА С УЧЕТОМ КОНКРЕТНОГО КУРСА ---
        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $courseSlug);

        // Открытые уроки/вебинары доступны любому залогиненному без покупки
        $isFreeLesson = (bool) $lesson->is_free;

        if (! $isFreeLesson && ! $hasLessonGrant && ! $lesson->isUnlockedBy($unlockedTariffs)) {
            return redirect()->route('student.course', $course->slug)
                ->with('error', 'Этот урок доступен в Блоке '.$lesson->block_number.'. Для просмотра необходимо оплатить доступ.');
        }
        // ==========================================
        // --- ТРЕКИНГ ПРОСМОТРА УРОКА (async) ---
        // ==========================================
        // Не dispatchим для админов (они просматривают уроки для проверки, это не учебная активность)
        if (! $user->is_admin) {
            TrackLessonViewJob::dispatch(
                userId: $user->id,
                lessonId: $lesson->id,
                courseId: $course->id,
                laravelSessionId: request()->session()->getId(),
                url: request()->fullUrl(),
                ipAddress: request()->ip(),
            );

            // Прана за просмотр открытого урока/вебинара (раз на урок благодаря
            // уникальному индексу по reason+source).
            if ($lesson->is_free) {
                $prana->award($user, 'open_lesson_view', $lesson);
            }
        }
        // ==========================================

        $lessons = $course->lessons()->forUserGroups($user)->orderBy('sort_order')->orderBy('created_at')->get();

        $currentNote = null;
        // Заметка может быть сохранена ДО отметки «пройдено», поэтому читаем
        // через lessonProgress (без фильтра по is_completed).
        $progressRow = $user->lessonProgress()->where('lesson_id', $lesson->id)->first();
        if ($progressRow) {
            $currentNote = $progressRow->pivot->notes;
        }

        $youtubeId = $this->parseVideoId($lesson->youtube_url, 'youtube');
        $rutubeId = $this->parseVideoId($lesson->rutube_url, 'rutube');

        // In-video resume (H1450, W2). Пока флаг video_resume выключен, JS ничего
        // не шлёт и баннер «продолжить» не показывается — эти переменные лежат
        // в вью мёртвым грузом, ровно как до H1450.
        $videoResumeEnabled = (bool) config('features.video_resume');
        // H1451 W3 (true-redo): multi-field resolve — video_url preferred, then
        // youtube_url / rutube_url if staff pasted a kinescope.io link there.
        $kinescopeEmbedUrl = KinescopePilot::embedForLesson(
            $lesson,
            $course->id ?? null
        );
        $resumePosition = null;
        $resumeDuration = null;
        if ($videoResumeEnabled) {
            $lessonView = LessonView::where('user_id', $user->id)
                ->where('lesson_id', $lesson->id)
                ->first();

            if ($lessonView && ! $lessonView->is_completed && (int) $lessonView->last_position_seconds > 0) {
                $resumePosition = (int) $lessonView->last_position_seconds;
                $resumeDuration = $lessonView->video_duration_seconds ? (int) $lessonView->video_duration_seconds : null;
            }
        }

        // Запись ещё не залита (живое занятие только состоится — например, пробное).
        // Подтягиваем событие расписания на эту дату, чтобы показать «Состоится … +
        // Подключиться к Zoom» вместо пустого плеера. n8n позже дозальёт видео.
        $upcomingSession = null;
        if (empty($youtubeId) && empty($rutubeId) && empty($kinescopeEmbedUrl) && empty($lesson->video_url) && $lesson->lesson_date) {
            $upcomingSession = Schedule::query()
                ->where('course_id', $course->id)
                ->where('group_id', $lesson->group_id)
                ->whereDate('start', $lesson->lesson_date)
                ->orderBy('start')
                ->first();
        }

        // ==========================================
        // --- БЛОК ОБРАБОТКИ JSON ТРАНСКРИПЦИИ ---
        // ==========================================
        // Разбор JSON-расшифровки в предложения с таймкодами вынесен в TranscriptParser
        // (переиспользуется блоком лендинга «Стенограмма вебинара»). Кэш — внутри сервиса.
        $transcriptSentences = TranscriptParser::sentencesFromPublicFile($lesson->transcript_file);

        // Открыт ли приём работ ИМЕННО ДЛЯ ЭТОГО студента (H1764). Считается
        // один раз здесь и передаётся в шаблон: витрина и серверный гейт
        // обязаны отвечать на этот вопрос одинаково.
        $homeworkOpen = $lesson->homeworkOpenFor($user);

        // Домашняя работа этого студента по уроку (если приём открыт)
        $homeworkSubmission = null;
        if ($homeworkOpen) {
            $homeworkSubmission = $user->homeworkSubmissions()
                ->where('lesson_id', $lesson->id)
                ->with(['comments.files', 'comments.author'])
                ->first();
        }

        // Передаем переменную $transcriptSentences в шаблон
        return view('student.lesson', compact('course', 'lesson', 'lessons', 'youtubeId', 'rutubeId', 'currentNote', 'unlockedTariffs', 'transcriptSentences', 'homeworkOpen', 'homeworkSubmission', 'upcomingSession', 'videoResumeEnabled', 'resumePosition', 'resumeDuration', 'kinescopeEmbedUrl'));
    }

    /**
     * Отметить урок как пройденный
     */
    public function completeLesson($courseSlug, $lessonId, PranaService $prana)
    {
        $user = auth()->user();
        $this->ensureLessonAccessible($user, $courseSlug, $lessonId);

        // Уже пройден? Проверяем по реально завершённой строке pivot,
        // а не по любой записи (которую могла создать saveNote с is_completed=false).
        $alreadyCompleted = $user->completedLessons()->where('lesson_id', $lessonId)->exists();

        if (! $alreadyCompleted) {
            // Если строка pivot уже есть (от saveNote) — апдейтим её,
            // иначе attach. Иначе создадим дубль pivot-строки (на таблице
            // lesson_user нет уникального индекса по user_id+lesson_id).
            $hasPivotRow = $user->lessonProgress()->where('lesson_id', $lessonId)->exists();
            if ($hasPivotRow) {
                $user->lessonProgress()->updateExistingPivot($lessonId, [
                    'is_completed' => true,
                ]);
            } else {
                $user->lessonProgress()->attach($lessonId, [
                    'is_completed' => true,
                ]);
            }

            $course = Course::where('slug', $courseSlug)->firstOrFail();
            $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

            // Начисление за урок (идемпотентно по lesson_id).
            $prana->award($user, 'lesson_complete', $lesson);

            // Baseline-телеметрия ремейка (H962, спека §4): ручное «усвоено».
            // Только при НОВОМ завершении — повторный клик события не пишет.
            app(CabinetTelemetry::class)->emit(
                user: $user,
                event: ActivityEvent::LESSON_MARK_MASTERED,
                data: ['course_id' => $course->id, 'lesson_id' => $lesson->id],
                request: request(),
            );

            // Если этот урок закрыл весь курс — начисляем бонус за курс
            // (тоже идемпотентно по course_id). Гейтим по членству в группах
            // курса — иначе на курсах, где все уроки is_free=true, любой
            // залогиненный юзер мог бы прокликать course_complete (+500🪷).
            $userInCourseGroups = $course->groups()
                ->whereIn('groups.id', $user->groups->pluck('id'))
                ->exists();

            if ($userInCourseGroups) {
                // Считаем только уроки, видимые этому студенту по его группе
                // (для курсов из 2 потоков total у каждой группы свой).
                $totalLessons = $course->lessons()->forUserGroups($user)->count();
                $completedLessons = $user->completedLessons()
                    ->where('lessons.course_id', $course->id)
                    ->count();

                if ($totalLessons > 0 && $completedLessons >= $totalLessons) {
                    $prana->award($user, 'course_complete', $course);
                }
            }
        }

        return redirect()->back()->with('success', 'Урок пройден!');
    }

    /**
     * Сохранение заметки
     */
    public function saveNote(Request $request, $courseSlug, $lessonId)
    {
        $user = auth()->user();
        $request->validate(['notes' => 'nullable|string|max:5000']);
        $this->ensureLessonAccessible($user, $courseSlug, $lessonId);

        // Через lessonProgress (без фильтра по is_completed) — иначе на пользователе,
        // у которого уже есть строка pivot с is_completed=false (от прошлой заметки),
        // мы создавали бы дубль вместо апдейта.
        $existing = $user->lessonProgress()->where('lesson_id', $lessonId)->first();

        if ($existing) {
            $user->lessonProgress()->updateExistingPivot($lessonId, ['notes' => $request->input('notes')]);
        } else {
            $user->lessonProgress()->attach($lessonId, [
                'is_completed' => false,
                'notes' => $request->input('notes'),
            ]);
        }

        return redirect()->back()->with('success', 'Заметка сохранена');
    }

    /**
     * Проверяет, что урок принадлежит курсу из URL и доступен пользователю
     * (свободный или оплачен через full/block_X). Иначе — abort(403/404).
     * Защищает completeLesson/saveNote от IDOR на уроки чужих курсов.
     */
    private function ensureLessonAccessible($user, string $courseSlug, $lessonId): void
    {
        $course = Course::where('slug', $courseSlug)->firstOrFail();
        $lesson = Lesson::where('course_id', $course->id)->findOrFail($lessonId);

        if (! $lesson->isVisibleToGroupsOf($user)) {
            abort(403, 'Этот урок относится к другой группе курса.');
        }

        if ($lesson->is_free) {
            return;
        }

        $unlocked = $this->getUserUnlockedTariffs($user->id, $courseSlug);

        if (! $lesson->isUnlockedBy($unlocked)) {
            abort(403, 'Нет доступа к этому уроку.');
        }
    }

    /**
     * Скачать архив со всеми материалами курса.
     * Учитывает права доступа студента (оплаченные блоки).
     */
    public function downloadCourseMaterials(string $slug, CourseMaterialsArchiver $archiver)
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        // Проверяем, что курс доступен этому студенту (он в нужной группе).
        // Используем is_active (а не is_visible) — это видимость в ЛК, согласованно с dashboard/showCourse.
        $course = Course::where('slug', $slug)
            ->where('is_active', true)
            ->whereHas('groups', function ($query) use ($userGroupIds) {
                $query->whereIn('groups.id', $userGroupIds);
            })
            ->firstOrFail();

        $unlockedTariffs = $this->getUserUnlockedTariffs($user->id, $slug);

        if (empty($unlockedTariffs)) {
            return back()->with('error', 'У вас нет оплаченных блоков для этого курса.');
        }

        try {
            return $archiver->buildForUser($course, $user, $unlockedTariffs);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Скачивание сертификата
     */
    public function downloadCertificate($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);
        $pdf = $service->generatePdf($certificate);

        return $pdf->download('Certificate_'.$certificate->course->id.'.pdf');
    }

    /**
     * Скачивание сертификата картинкой (JPEG).
     */
    public function downloadCertificateImage($id, CertificateService $service)
    {
        $certificate = auth()->user()->certificates()->with('course')->findOrFail($id);

        try {
            $jpeg = $service->generateJpegBytes($certificate);
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return response()->streamDownload(
            fn () => print $jpeg,
            'Certificate_'.$certificate->course->id.'.jpg',
            ['Content-Type' => 'image/jpeg'],
        );
    }

    /**
     * === ВСПОМОГАТЕЛЬНЫЙ МЕТОД: Парсер ссылок видео ===
     */
    private function parseVideoId(?string $url, string $platform): ?string
    {
        if (! $url) {
            return null;
        }

        if ($platform === 'youtube') {
            preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/', $url, $matches);

            return $matches[1] ?? null;
        }

        if ($platform === 'rutube') {
            $parsed = parse_url($url);
            $path = $parsed['path'] ?? '';
            $query = $parsed['query'] ?? '';

            if (preg_match('/([a-zA-Z0-9]{32})/', $path, $matches)) {
                $cleanId = $matches[1];
                if (! empty($query)) {
                    return $cleanId.'?'.$query;
                }

                return $cleanId;
            }
        }

        return null;
    }

    /**
     * Раздел «Открытые уроки / вебинары» — доступен любому залогиненному студенту.
     * Показывает все уроки с is_free=true (независимо от покупок и групп).
     */
    public function openLessons()
    {
        $lessons = Lesson::free()
            ->where('is_published', true)
            ->with('course:id,title,slug')
            ->orderByDesc('lesson_date')
            ->orderByDesc('id')
            ->get();

        return view('student.open-lessons', compact('lessons'));
    }

    /**
     * H1680 — Wave 2: cabinet skill-drill strip. Links out to the existing
     * free /lila drills, DISTINCT from the FSRS review loop at /dvaram/koloda —
     * short single-item practice, no spaced-repetition scheduling here.
     * Static curated list (the drills themselves live in public/lila/, not
     * in the DB) — matches the "not FSRS" scope of this handoff.
     */
    public function skillDrills()
    {
        $drills = [
            ['family' => 'sort', 'label' => 'Гласные: долгие и краткие', 'url' => '/lila/sort/vowel-length/'],
            ['family' => 'match', 'label' => 'IAST ↔ кириллица', 'url' => '/lila/match/iast-cyrillic/'],
            ['family' => 'match', 'label' => 'Кочергина, урок 1', 'url' => '/lila/match/kochergina-l1/'],
            ['family' => 'roots', 'label' => 'Корни: топ-25', 'url' => '/lila/roots/top-25/'],
            ['family' => 'ligatures', 'label' => 'Лигатуры: топ-10', 'url' => '/lila/ligatures/top-10/'],
            ['family' => 'cloze', 'label' => 'Ранг корня: клоуз', 'url' => '/lila/cloze/root-rank/'],
        ];

        return view('student.skill-drills', compact('drills'));
    }

    public function messages()
    {
        $user = auth()->user();

        // Добавили круглые скобки () и явно указали таблицу, чтобы избежать конфликтов!
        $userGroupIds = $user->groups()->pluck('groups.id')->toArray();

        $messages = Announcement::where('is_published', true)
            ->orderBy('created_at', 'desc')
            ->get()
            ->filter(function ($announcement) use ($userGroupIds) {
                if (empty($announcement->target_groups)) {
                    return true;
                }

                return count(array_intersect($announcement->target_groups, $userGroupIds)) > 0;
            });

        return view('student.messages', compact('messages'));
    }
}
