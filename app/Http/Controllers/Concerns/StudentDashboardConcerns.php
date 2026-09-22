<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\TrackLessonViewJob;
use App\Models\ActivityEvent;
use App\Models\Course;
use App\Models\CourseFavorite;
use App\Models\CourseWaitlistItem;
use App\Models\HomeworkSubmission;
use App\Models\Lesson;
use App\Models\LessonAccessGrant;
use App\Models\LessonView;
use App\Models\Payment;
use App\Models\PranaPerk;
use App\Models\PranaRedemption;
use App\Models\Schedule;
use App\Models\SubscriberMagnet;
use App\Models\User;
use App\Models\WaitlistVote;
use App\Services\AccessDiagnosticsService;
use App\Services\Activity\CabinetTelemetry;
use App\Services\Activity\FunnelTelemetry;
use App\Services\Cabinet\GrammarLadder;
use App\Services\Cabinet\RecordingsCatalog;
use App\Services\Cabinet\RecoveryState;
use App\Services\Cabinet\RecoveryStateResolver;
use App\Services\CourseContinuationBanner;
use App\Services\DebtPaymentResolver;
use App\Services\HindiAttachmentDrills;
use App\Services\HindiProgrammePlaylist;
use App\Services\HindiTranscriptDrills;
use App\Services\Leaderboard\LeaderboardService;
use App\Services\Learning\ExternalLearningProgressService;
use App\Services\Membership\ClubEntitlement;
use App\Services\Membership\ClubMembershipService;
use App\Services\Membership\RecordingAccessPolicy;
use App\Services\Prana\PranaService;
use App\Services\Prana\PranaSettings;
use App\Services\Schedule\TextbookScale;
use App\Services\StudentDebtsService;
use App\Support\Badges;
use App\Support\KinescopePilot;
use App\Support\OnboardingChecklist;
use App\Support\PranaLeaderboard;
use App\Support\TranscriptParser;
use App\Support\VisualDcsEntitlement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Domain-scoped concern for StudentController — extracted by H4978 split
 * (recovered under H5245 PR-sweep). Implementations are main's current bodies.
 */
trait StudentDashboardConcerns
{
/**
     * Главная панель (Мои курсы)
     */
    public function dashboard()
    {
        $user = auth()->user();
        $userGroupIds = $user->groups->pluck('id');

        ['courses' => $courses, 'nextLessonByCourseId' => $nextLessonByCourseId, 'completedLessonIds' => $completedLessonIds]
            = $this->loadDashboardCourseData($user, $userGroupIds);

        ['certificates' => $certificates, 'pranaTransactions' => $pranaTransactions, 'pranaRewards' => $pranaRewards,
            'pranaReasons' => $pranaReasons, 'pranaLeaderboards' => $pranaLeaderboards, 'pranaLeaderboard' => $pranaLeaderboard,
            'gamificationBoards' => $gamificationBoards, 'badges' => $badges, 'pranaPerks' => $pranaPerks,
            'pranaRedemptions' => $pranaRedemptions] = $this->loadDashboardGamificationData($user);

        ['debts' => $debts, 'debtsByCourseId' => $debtsByCourseId, 'paidUntilByCourseId' => $paidUntilByCourseId,
            'debtPayOptions' => $debtPayOptions] = $this->loadDashboardDebtsData($user, $courses);

        $trialLessons = $this->loadDashboardTrialLessons($user, $courses);

        ['onboarding' => $onboarding, 'homeworkAlerts' => $homeworkAlerts]
            = $this->loadDashboardOnboardingAndHomeworkAlerts($user);

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

        $metrikaFirstCabinetAction = $this->emitDashboardTelemetry($user, $recovery, $debts->count());

        ['accessSelfService' => $accessSelfService, 'accessProfileSummary' => $accessProfileSummary]
            = $this->buildDashboardAccessProfileData($user, $courses->count());

        $canvasByCourseId = $this->buildDashboardTextbookCanvas($courses, $user);

        $viewData = compact(
            'courses',
            'canvasByCourseId',
            'nextLessonByCourseId',
            'metrikaFirstCabinetAction',
            'certificates',
            'pranaTransactions',
            'pranaRewards',
            'pranaLeaderboard',
            'pranaLeaderboards',
            'gamificationBoards',
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
            'accessSelfService',
            'accessProfileSummary',
        );

        $viewData = array_merge($viewData, $this->buildDashboardClubAndProgrammeData($user));
        $viewData = array_merge($viewData, $this->buildDashboardWaitlistData($user));

        // H5134 — секция «Избранное» в кабинете (сердечки). Flag OFF → пустая
        // коллекция, кабинет байт-стабилен; заголовок рисует partial по флагу.
        $favorites = collect();
        if (config('features.course_favorites', false)) {
            $favoriteRows = CourseFavorite::query()
                ->where('user_id', $user->id)
                ->with(['course:id,slug,title,is_visible'])
                ->orderByDesc('id')
                ->get();
            $favoriteWaitlistTitles = CourseWaitlistItem::query()
                ->whereIn('slug', $favoriteRows->pluck('waitlist_slug')->filter()->all())
                ->pluck('course_title', 'slug');
            $favorites = $favoriteRows->map(fn (CourseFavorite $favorite): object => (object) [
                'id' => $favorite->id,
                'heartKey' => $favorite->heartKey(),
                'title' => $favorite->course?->title
                    ?? $favoriteWaitlistTitles[$favorite->waitlist_slug]
                    ?? (string) $favorite->waitlist_slug,
                'url' => $favorite->course && $favorite->course->is_visible
                    ? route('shop.course.show', $favorite->course->slug)
                    : null,
                'createdAt' => $favorite->created_at,
            ]);
        }
        $viewData['favorites'] = $favorites;

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

        $visualContinue = $this->continueLearningVisualDcs(auth()->user());
        if ($visualContinue !== null) {
            return $visualContinue;
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

/**
     * H2482 — resume a started VisualDCS object when no next lesson is waiting.
     * Never outranks debt / homework / trial / next lesson.
     *
     * @return array<string, mixed>|null
     */
    private function continueLearningVisualDcs($user): ?array
    {
        if (! $user) {
            return null;
        }
        $anyFlag = config('features.visualdcs_verb')
            || config('features.visualdcs_nominal')
            || config('features.visualdcs_passage');
        if (! $anyFlag || ! VisualDcsEntitlement::hasFullAccess($user)) {
            return null;
        }

        $latest = app(ExternalLearningProgressService::class)->latestForUser($user);
        if (! $latest) {
            return null;
        }

        $flag = (string) (config('visualdcs.flag_by_surface.'.$latest->surface) ?? '');
        if ($flag === '' || ! config('features.'.$flag, false)) {
            return null;
        }

        return [
            'kind' => 'visualdcs',
            'title' => 'Продолжить тренажёр',
            'body' => match ($latest->surface) {
                'verb' => 'Глагол',
                'nominal' => 'Имя',
                default => 'Пассаж',
            },
            'meta' => $latest->object_id,
            'cta' => [
                'label' => 'Продолжить',
                'url' => route('student.visualdcs.show', [
                    $latest->surface,
                    rawurlencode($latest->object_id),
                ]),
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

    private function buildDashboardAccessProfileData($user, int $courseCount): array
    {
        $accessSelfService = (bool) config('features.access_self_service', false);
        $accessProfileSummary = $accessSelfService
            ? app(AccessDiagnosticsService::class)->profileSummary($user, $courseCount)
            : null;

        return compact('accessSelfService', 'accessProfileSummary');
    }

    private function buildDashboardClubAndProgrammeData($user): array
    {
        // H2644: клубная полка и карточка членства. Оба ключа ВСЕГДА определены —
        // партиалы решают по ним, рисовать ли себя; при выключенном флаге это
        // null + пустая коллекция, и кабинет остаётся байт-стабильным.
        $clubEntitlement = app(ClubEntitlement::class);
        $clubMembership = $clubEntitlement->enabled()
            ? app(ClubMembershipService::class)->activeFor($user)
            : null;
        $clubShelf = $clubEntitlement->shelfFor($user);

        // H2441: Hindi programme playlist card. Null when flag OFF so classic
        // cabinet stays inert; the page itself never requires cabinet_hybrid.
        $hindiPlaylistService = app(HindiProgrammePlaylist::class);
        $hindiPlaylist = $hindiPlaylistService->enabled()
            ? $hindiPlaylistService->summaryFor($user)
            : null;
        $hindiTeacherBrief = $hindiPlaylistService->teachesHindi($user)
            ? HindiProgrammePlaylist::TEACHER_BRIEF_URL
            : null;

        return compact('clubMembership', 'clubShelf', 'hindiPlaylist', 'hindiTeacherBrief');
    }

    private function buildDashboardTextbookCanvas($courses, $user): array
    {
        // H4435 (MG 08-09): канва по курсам-учебникам — позиция студента и
        // курсор группы. Две шкалы раздельно: наши занятия vs уроки учебника.
        $canvasByCourseId = [];
        foreach ($courses as $course) {
            $family = TextbookScale::courseFamilyPublic((string) $course->title);
            if ($family === null) {
                continue;
            }
            $total = TextbookScale::families()[$family]['total'];
            $lessons = Lesson::where('course_id', $course->id)
                ->whereNotNull('lesson_date')->orderBy('lesson_date')->get();
            $groupCursor = TextbookScale::cursor($lessons, $family);

            // Позиция студента: записи уроков до даты его последнего факта в этой группе.
            $studentCursor = 0;
            $fact = $user->attendances()
                ->whereIn('schedule_id', Schedule::where('group_id', $course->groups->pluck('id'))->pluck('id'))
                ->latest('created_at')->first();
            if ($fact) {
                $factDay = $fact->created_at->copy()->startOfDay();
                $studentCursor = TextbookScale::cursor(
                    $lessons->filter(fn ($l) => $l->lesson_date !== null && $l->lesson_date->startOfDay()->lte($factDay)),
                    $family,
                );
            }

            $canvasByCourseId[$course->id] = [
                'family' => $family,
                'total' => $total,
                'group' => $groupCursor,
                'student' => $studentCursor,
            ];
        }

        return $canvasByCourseId;
    }

    private function buildDashboardWaitlistData($user): array
    {
        // Список ожидания (MG 31-08-2026, H3815): строки для голосования в
        // кабинете. Flag OFF → пустая коллекция, кабинет байт-стабилен.
        $waitlistItems = config('features.waitlist_voting', false)
            ? CourseWaitlistItem::query()
                ->where('is_listed', true)
                ->whereNotIn('status', [CourseWaitlistItem::STATUS_CLOSED, CourseWaitlistItem::STATUS_SCHEDULED])
                ->orderBy('sort_order')->orderBy('id')
                ->withCount(['votes as voted_by_me' => fn ($q) => $q->where('user_id', $user->id)])
                ->withCount('votes')
                ->get()
            : collect();

        // H4206: моё пожелание времени по каждой строке («Голос учтён · утром»).
        $waitlistMyPrefs = $waitlistItems->isNotEmpty()
            ? WaitlistVote::query()
                ->where('user_id', $user->id)
                ->whereIn('course_waitlist_item_id', $waitlistItems->modelKeys())
                ->pluck('slot_preference', 'course_waitlist_item_id')
            : collect();

        return compact('waitlistItems', 'waitlistMyPrefs');
    }

    private function emitDashboardTelemetry($user, $recovery, int $debtsCount): bool
    {
        // Baseline-телеметрия ремейка (H962, спека §4). mode: normal|recovery.
        // Метрика first_cabinet_action (MG 18-09): флаг считается ЗДЕСЬ, до
        // эмита — emitFirstCabinetAction срабатывает внутри
        // CabinetTelemetry::emit, поэтому «события ещё нет» в этой точке
        // означает, что ЭТА загрузка — первое действие студента в кабинете
        // (маркер в view стреляет goal ровно один раз; truth по-прежнему
        // в activity_events). Возврат идёт в $viewData — в compact() ниже.
        $metrikaFirstCabinetAction = ! app(FunnelTelemetry::class)->hasFirstCabinetAction($user);

        app(CabinetTelemetry::class)->emit(
            user: $user,
            event: ActivityEvent::CABINET_HOME_VIEW,
            data: [
                'mode' => $recovery->mode(),
                'debts' => $debtsCount,
                'reason' => $recovery->reason,
            ],
            request: request(),
        );

        return $metrikaFirstCabinetAction;
    }

    private function loadDashboardCourseData($user, $userGroupIds): array
    {
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

        return compact('courses', 'nextLessonByCourseId', 'completedLessonIds');
    }

    private function loadDashboardDebtsData($user, $courses): array
    {
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

        return compact('debts', 'debtsByCourseId', 'paidUntilByCourseId', 'debtPayOptions');
    }

    private function loadDashboardGamificationData($user): array
    {
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

        // Таблица лидеров: Week / Month / All Time (Memrise-аналог, H2051).
        $pranaLeaderboards = PranaLeaderboard::rowsByPeriod(10, $user->id);
        $pranaLeaderboard = $pranaLeaderboards['all']; // BC for partials/tests
        // H2054 — multi-board gamification (prana / SRS / lila / Memrise import / combined).
        // Disabled boards drop out via config/leaderboards.php enabled flags.
        $limit = (int) config('leaderboards.limit', 10);
        $gamificationBoards = app(LeaderboardService::class)->allEnabledBoards($limit, $user->id);
        // Бейджи (достижения) — вычисляются из сигналов прогресса/праны.
        $badges = Badges::for($user);
        // Магазин праны (spend-sink): активные перки + последние покупки студента.
        $pranaPerks = PranaPerk::shownInShop()->get();
        $pranaRedemptions = PranaRedemption::where('user_id', $user->id)
            ->latest()->limit(5)->get();

        return compact(
            'certificates',
            'pranaTransactions',
            'pranaRewards',
            'pranaReasons',
            'pranaLeaderboards',
            'pranaLeaderboard',
            'gamificationBoards',
            'badges',
            'pranaPerks',
            'pranaRedemptions',
        );
    }

    private function loadDashboardOnboardingAndHomeworkAlerts($user): array
    {
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

        return compact('onboarding', 'homeworkAlerts');
    }

    private function loadDashboardTrialLessons($user, $courses)
    {
        // Отдельно открытые уроки (например, оплаченное пробное занятие): курсы, к
        // которым нет полного доступа по группам, но есть персональный grant на урок.
        return LessonAccessGrant::query()
            ->where('user_id', $user->id)
            ->active()
            ->whereNotIn('course_id', $courses->pluck('id')->all())
            ->with(['lesson:id,course_id,title,lesson_date', 'course:id,slug,title'])
            ->get()
            ->filter(fn (LessonAccessGrant $g) => $g->lesson && $g->course)
            ->values();
    }
}
