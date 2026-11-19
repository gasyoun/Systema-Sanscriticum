<?php

namespace App\Services\SupportQuestions;

use App\Models\SupportQuestionClassification;
use App\Models\SupportQuestionWeeklySnapshot;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
use App\Models\TelegramSupportScanDay;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Недельный агрегатор студенческих вопросов Telegram (H5709).
 *
 * Окно — календарные даты Europe/Moscow: старт понедельник 00:00 MSK,
 * конец — следующий понедельник 00:00 MSK (эксклюзивно). Границы считаются
 * в Europe/Moscow ЯВНО (не app.timezone):Dst-расхождение с Европой/Рига
 * после 25-10-2026 не должно сдвигать недельные границы.
 *
 * Инвариант reconciliation (проверяется тестом и в рантайме):
 *   questions + not_questions + excluded.service_message + excluded.bot
 *   + excluded.duplicate_import == incoming_total
 * duplicate_import всегда 0: уникальный ключ
 * (account, chat, message_id) делает дубли невозможными на инжесте, а
 * upsert классификаций идемпотентен — но причина остаётся в леджере
 * исключений, чтобы сумма билась с источником поимённо.
 */
class WeeklyQuestionAnalytics
{
    /** Спека H5709: бэкфилл доступных сообщений с 07-07-2026 (неделя, содержащая дату). */
    public const BACKFILL_SINCE = '2026-07-06';

    /**
     * Версия семантики покрытия/полноты (H5768). v1 доказывала полноту
     * «входящими каждый день + свежим синком сейчас» — тихий просканенный
     * день считался неполным, непросканированный источник был невидим.
     * v2 = дневные доказательства успешного скана инжестера по каждому
     * настроенному источнику. Снапшоты разных версий не сравниваются.
     */
    public const COVERAGE_VERSION = 2;

    public function __construct(
        private readonly QuestionMessageClassifier $classifier,
        private readonly QuestionStudentAuthority $authority,
    ) {}

    /**
     * Дефолтное окно — предыдущая ЗАВЕРШЁННАЯ неделя Mon–Sun (Europe/Moscow).
     *
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public static function defaultWindow(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now('Europe/Moscow'))->timezone('Europe/Moscow');
        $thisMonday = $now->startOfWeek(CarbonInterface::MONDAY);

        return [
            'from' => $thisMonday->modify('-7 days'),
            'to' => $thisMonday,
        ];
    }

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable}
     */
    public static function windowForDates(string $from, string $to): array
    {
        $fromDate = CarbonImmutable::parse($from, 'Europe/Moscow')->startOfDay();
        $toDate = CarbonImmutable::parse($to, 'Europe/Moscow')->startOfDay();

        if ($toDate <= $fromDate) {
            throw new \InvalidArgumentException('--to must be strictly after --from (exclusive next-day end).');
        }

        return ['from' => $fromDate, 'to' => $toDate];
    }

    /**
     * Считает агрегаты окна. $persist=true дополнительно пишет посообщенные
     * классификации (upsert по message+version) и снапшот недели.
     *
     * @return array{payload: array<string, mixed>, is_incomplete: bool, incompleteness_reason: string|null}
     */
    public function computeWindow(
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $persist = false,
    ): array {
        $messages = TelegramSupportMessage::query()
            ->with('chat')
            ->where('sent_at', '>=', $this->localBound($from))
            ->where('sent_at', '<', $this->localBound($to))
            ->orderBy('id')
            ->get();

        $resolver = QuestionPopulationResolver::forMessages($messages, $this->authority);

        $populations = [
            SupportQuestionClassification::POPULATION_STUDENT => $this->emptyPopulation(),
            SupportQuestionClassification::POPULATION_ENQUIRY => $this->emptyPopulation(),
            SupportQuestionClassification::POPULATION_STAFF_INTERNAL => $this->emptyPopulation(),
            SupportQuestionClassification::POPULATION_UNKNOWN => $this->emptyPopulation(),
        ];

        $excluded = [
            'service_message' => 0,
            'bot' => 0,
            'duplicate_import' => 0,
        ];
        $notQuestions = 0;
        $outgoing = 0;
        /** @var array<int, int> $questionersByPopulation уникальные спрашивающие (внутренний дедуп, наружу — только счёт) */
        $questionersByPopulation = [];
        /** @var list<int> $studentQuestionUserIds авторы student-вопросов — для согласованной когорты нормирования (H5768) */
        $studentQuestionUserIds = [];

        foreach ($messages as $message) {
            if ($message->direction !== 'incoming') {
                $outgoing++;

                continue;
            }

            $verdict = $resolver->resolve($message);

            if ($verdict['exclusion_reason'] !== null) {
                $excluded[$verdict['exclusion_reason']] = ($excluded[$verdict['exclusion_reason']] ?? 0) + 1;

                if ($persist) {
                    $this->persistClassification($message, $verdict, null);
                }

                continue;
            }

            $classification = $this->classifier->classifyMessage((string) $message->text);
            $isQuestion = $classification['is_question'];

            // Пишем сразу в $populations[...] — присваивание в локальную
            // $population копировало бы массив по значению (замерено H5709:
            // unique_questioners рос, а счётчики оставались нулями).
            $populationCode = $verdict['population'];
            $populations[$populationCode]['incoming']++;

            if ($isQuestion) {
                $populations[$populationCode]['questions']++;
                if ($classification['primary_category'] !== null) {
                    $category = $classification['primary_category'];
                    $populations[$populationCode]['by_category'][$category] =
                        ($populations[$populationCode]['by_category'][$category] ?? 0) + 1;
                } else {
                    $populations[$populationCode]['unclassified']++;
                }
                $senderKey = $message->telegram_support_contact_id
                    ?: -$message->telegram_support_chat_id; // ЛС без контакта: уникален сам чат
                $questionersByPopulation[$populationCode][$senderKey] = 1;
                if ($populationCode === SupportQuestionClassification::POPULATION_STUDENT) {
                    $studentQuestionUserIds[] = (int) ($verdict['user_id'] ?? 0);
                }
            } else {
                $notQuestions++;
            }

            if ($persist) {
                $this->persistClassification($message, $verdict, $classification);
            }
        }

        foreach ($populations as $code => $population) {
            $populations[$code]['unique_questioners'] = count($questionersByPopulation[$code] ?? []);
            $populations[$code]['by_category'] = $this->sortedCategories($population['by_category']);
            $denominator = $population['questions'];
            $populations[$code]['unclassified_share'] = $denominator > 0
                ? round($population['unclassified'] / $denominator, 4)
                : null;
            $populations[$code]['question_share'] = $population['incoming'] > 0
                ? round($population['questions'] / $population['incoming'], 4)
                : null;
        }

        $incomingTotal = $messages->where('direction', 'incoming')->count();
        $questionsTotal = array_sum(array_map(
            static fn (array $p): int => $p['questions'],
            $populations,
        ));

        $reconciles = ($questionsTotal + $notQuestions + $excluded['service_message']
            + $excluded['bot'] + $excluded['duplicate_import']) === $incomingTotal;

        $coverage = $this->coverage($messages, $from, $to);
        $activity = $this->activity(
            $from,
            $to,
            $populations[SupportQuestionClassification::POPULATION_STUDENT]['questions'],
            $studentQuestionUserIds,
        );

        $payload = [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'week_start' => $from->toDateString(),
                'days' => $from->diffInDays($to),
            ],
            'classifier_version' => QuestionMessageClassifier::VERSION,
            'coverage_version' => self::COVERAGE_VERSION,
            'student_definition' => QuestionPopulationResolver::STUDENT_DEFINITION,
            'populations' => $populations,
            'totals' => [
                'incoming' => $incomingTotal,
                'outgoing_excluded' => $outgoing,
                'external_questions' => $populations[SupportQuestionClassification::POPULATION_STUDENT]['questions']
                    + $populations[SupportQuestionClassification::POPULATION_ENQUIRY]['questions'],
            ],
            'activity' => $activity,
            'reconciliation' => [
                'incoming_total' => $incomingTotal,
                'questions' => $questionsTotal,
                'not_questions' => $notQuestions,
                'excluded' => $excluded,
                'sum_check' => $reconciles,
            ],
            'coverage' => $coverage,
            'generated_at' => now()->toIso8601String(),
        ];

        [$isIncomplete, $reason] = $this->incompleteness($payload);

        return [
            'payload' => $payload,
            'is_incomplete' => $isIncomplete,
            'incompleteness_reason' => $reason,
        ];
    }

    /**
     * Пишет (upsert) снапшот недели. Пересчёт той же недели легален:
     * обновляется payload той же (week_start, classifier_version) строки.
     */
    public function persistSnapshot(array $payload, bool $isIncomplete, ?string $reason): SupportQuestionWeeklySnapshot
    {
        return SupportQuestionWeeklySnapshot::updateOrCreate(
            [
                'week_start' => $payload['window']['week_start'],
                'classifier_version' => $payload['classifier_version'],
            ],
            [
                'payload' => $payload,
                'is_incomplete' => $isIncomplete,
                'incompleteness_reason' => $reason,
            ],
        );
    }

    /**
     * @return Collection<int, SupportQuestionWeeklySnapshot>
     */
    public function recentSnapshots(int $weeks = 5): Collection
    {
        return SupportQuestionWeeklySnapshot::query()
            ->where('classifier_version', QuestionMessageClassifier::VERSION)
            ->orderByDesc('week_start')
            ->limit($weeks)
            ->get();
    }

    /**
     * Сравнение допустимо только при равной популяционной сфере, версии
     * классификатора, семантике покрытия, охвате источников, определении
     * студента и полноте обоих окон; иначе проценты изменения подавляются
     * (возврат null-дельт). Снапшоты до H5768 не имеют coverage_version и
     * source_scope — с ними сравнение подавляется автоматически.
     *
     * @return array{ready: bool, reason: string|null}
     */
    public static function comparisonReady(array $current, ?array $previous): array
    {
        if ($previous === null) {
            return ['ready' => false, 'reason' => 'no previous snapshot'];
        }
        foreach (
            [
                'classifier_version' => 'classifier version differs',
                'coverage_version' => 'coverage semantics differ',
                'student_definition' => 'student definition differs',
            ] as $key => $why
        ) {
            if (($current[$key] ?? null) !== ($previous[$key] ?? null)) {
                return ['ready' => false, 'reason' => $why];
            }
        }
        $currentScope = $current['coverage']['source_scope']['fingerprint'] ?? null;
        $previousScope = $previous['coverage']['source_scope']['fingerprint'] ?? null;
        if ($currentScope === null || $previousScope === null || $currentScope !== $previousScope) {
            return ['ready' => false, 'reason' => 'source scope differs'];
        }
        if (! empty($previous['is_incomplete']) || ! empty($current['is_incomplete'])) {
            return ['ready' => false, 'reason' => 'incomplete window'];
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * Нормирование по активным студентам (H5768). Занесение занятия —
     * ЭКСКЛЮЗИВНЫМ концом окна: занятие следующего понедельника в
     * знаменатель не входит. Когорты числителя и знаменателя согласованы:
     * знаменатель — подтверждённые студенты (ТОТ ЖЕ авторитет
     * QuestionStudentAuthority: активная группа ИЛИ проведённый платёж) с
     * занятием в окне; в числителе нормирования — только вопросы студентов
     * из этой же когорты (вопросы «оплаченных, но без группы» видны отдельно
     * в questions_all_students). Нет занятий в окне — нормирование
     * недоступно (null), а не ноль.
     *
     * @param  list<int>  $studentQuestionUserIds
     * @return array<string, mixed>
     */
    private function activity(CarbonImmutable $from, CarbonImmutable $to, int $studentQuestions, array $studentQuestionUserIds): array
    {
        $activeUserIds = DB::table('group_user as gu')
            ->join('lessons as l', 'l.group_id', '=', 'gu.group_id')
            ->whereNull('gu.left_at')
            ->where('l.lesson_date', '>=', $from->toDateString())
            ->where('l.lesson_date', '<', $to->toDateString())
            ->select('gu.user_id')
            ->distinct()
            ->pluck('gu.user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        // Тот же студенческий авторитет, что и в числителе популяций.
        $confirmed = $this->authority->confirmedStudentMap(collect($activeUserIds));
        $activeConfirmed = array_keys(array_filter($confirmed, static fn (bool $is): bool => $is));

        $matchedQuestions = count(array_filter(
            $studentQuestionUserIds,
            static fn (int $id): bool => $id > 0 && in_array($id, $activeConfirmed, true),
        ));

        return [
            'definition' => 'confirmed_students_with_lesson_in_window',
            'window_end_exclusive' => true,
            'active_students' => count($activeConfirmed),
            'questions_all_students' => $studentQuestions,
            'questions_matched_cohort' => $matchedQuestions,
            'questions_per_100_active' => $activeConfirmed !== []
                ? round($matchedQuestions / count($activeConfirmed) * 100, 2)
                : null,
        ];
    }

    /**
     * Покрытие источников по АВТОРИТЕТНЫМ дневным доказательствам успешного
     * скана инжестера (H5768, coverage v2). «Входящие каждый день» — только
     * описательная метрика: одна капля сообщений в день не доказывает
     * полноту, а тихий полностью просканенный день — не пробел. Настроенный
     * охват (включённые аккаунты) фиксируется отпечатком (fingerprint) и
     * попадает в снапшот: сравнение окон с разным охватом запрещено.
     *
     * @return array<string, mixed>
     */
    private function coverage(Collection $messages, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $incoming = $messages->where('direction', 'incoming');
        $daysWithIncoming = $incoming
            ->map(fn (TelegramSupportMessage $m): string => $m->sent_at->timezone('Europe/Moscow')->toDateString())
            ->unique()
            ->count();

        $daysInWindow = (int) $from->diffInDays($to);
        $windowDays = [];
        for ($day = $from; $day->lt($to); $day = $day->addDay()) {
            $windowDays[] = $day->toDateString();
        }

        /** @var Collection<int, TelegramSupportAccount> $accounts */
        $accounts = TelegramSupportAccount::query()
            ->where('is_enabled', true)
            ->orderBy('name')
            ->get();
        $sourceNames = $accounts->pluck('name')->values()->all();

        $scanEvidence = [];
        foreach ($accounts as $account) {
            $scanned = TelegramSupportScanDay::query()
                ->where('account_name', $account->name)
                ->whereIn('day', $windowDays)
                ->pluck('day')
                ->map(static fn ($d): string => (string) $d)
                ->all();
            $scanEvidence[$account->name] = [
                'days_scanned' => count($scanned),
                'days_missing' => array_values(array_diff($windowDays, $scanned)),
                'last_successful_sync_at' => $account->last_successful_sync_at?->toIso8601String(),
                // Хвост окна не обрезан: успешный синк был ПОСЛЕ конца окна.
                'tail_covered' => $account->last_successful_sync_at !== null
                    && $account->last_successful_sync_at->gte($to),
                'has_error' => $account->last_sync_error !== null,
            ];
        }

        /** @var TelegramSupportAccount|null $account */
        $account = TelegramSupportAccount::query()->where('name', 'support')->first();
        $staleAfter = (int) config('services.telegram_support.stale_after_minutes', 15);
        $lastSynced = $account?->last_synced_at;

        $fresh = $account !== null
            && $account->is_enabled
            && $lastSynced !== null
            && $lastSynced->gt(now()->subMinutes($staleAfter))
            && $account->last_sync_error === null;

        return [
            'basis' => 'ingester_successful_scan_days',
            'source_scope' => [
                'accounts' => $sourceNames,
                'fingerprint' => hash('sha256', json_encode($sourceNames)),
            ],
            // Описательные метрики активности — НЕ доказательство полноты.
            'days_with_incoming' => $daysWithIncoming,
            'days_in_window' => $daysInWindow,
            'chats_active' => $messages->unique('telegram_support_chat_id')->count(),
            'private_chats' => $messages->filter(
                fn (TelegramSupportMessage $m): bool => ($m->chat?->type ?? null) === 'private'
            )->unique('telegram_support_chat_id')->count(),
            'groups' => $messages->filter(
                fn (TelegramSupportMessage $m): bool => in_array($m->chat?->type ?? '', ['group', 'supergroup'], true)
            )->unique('telegram_support_chat_id')->count(),
            'scan_evidence' => $scanEvidence,
            'sync' => [
                'account_present' => $account !== null,
                'last_synced_at' => $lastSynced?->toIso8601String(),
                'has_error' => $account?->last_sync_error !== null,
                'stale_after_minutes' => $staleAfter,
                'fresh' => $fresh,
            ],
        ];
    }

    /**
     * Полнота окна (H5768, v2): авторитет — успешные сканы инжестера по
     * каждому настроенному источнику за каждый день окна + отсутствие
     * текущей ошибки синка + успешный синк ПОСЛЕ конца окна (хвост окна не
     * обрезан) + сверка сумм. Дней без доказательств не бывает «полных»:
     * исторические окна до появления таблицы сканов честно неполные.
     *
     * @return array{0: bool, 1: string|null}
     */
    private function incompleteness(array $payload): array
    {
        $coverage = $payload['coverage'];
        if (($coverage['source_scope']['accounts'] ?? []) === []) {
            return [true, 'support_account_missing'];
        }
        foreach (($coverage['scan_evidence'] ?? []) as $source => $evidence) {
            if (! empty($evidence['has_error'])) {
                return [true, 'sync_error'];
            }
            if (empty($evidence['tail_covered'])) {
                return [true, 'sync_tail_stale'];
            }
            if (! empty($evidence['days_missing'])) {
                return [true, 'scan_evidence_missing'];
            }
        }
        if (($payload['reconciliation']['sum_check'] ?? false) !== true) {
            return [true, 'reconciliation_mismatch'];
        }

        return [false, null];
    }

    private function persistClassification(
        TelegramSupportMessage $message,
        array $verdict,
        ?array $classification,
    ): void {
        SupportQuestionClassification::updateOrCreate(
            [
                'telegram_support_message_id' => $message->id,
                'classifier_version' => QuestionMessageClassifier::VERSION,
            ],
            [
                'population' => $verdict['population'],
                'is_question' => $classification['is_question'] ?? false,
                'primary_category' => $classification['primary_category'] ?? null,
                'secondary_categories' => $classification['secondary_categories'] ?? null,
                'exclusion_reason' => $verdict['exclusion_reason'],
                'flags' => [
                    'note' => $verdict['note'],
                    'signals' => $classification['signals'] ?? [],
                    'hits' => $classification['hits'] ?? [],
                ],
            ],
        );
    }

    /**
     * Граница окна Europe/Moscow -> локальное время приложения для сравнения
     * с sent_at (колонка хранится в app.timezone).
     */
    private function localBound(CarbonImmutable $boundary): string
    {
        return $boundary
            ->timezone(config('app.timezone'))
            ->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, int>
     */
    private function sortedCategories(array $byCategory): array
    {
        uksort($byCategory, static fn (string $a, string $b): int => array_search($a, QuestionMessageClassifier::CATEGORY_ORDER, true)
            <=> array_search($b, QuestionMessageClassifier::CATEGORY_ORDER, true));

        return $byCategory;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPopulation(): array
    {
        return [
            'incoming' => 0,
            'questions' => 0,
            'by_category' => [],
            'unclassified' => 0,
            'unclassified_share' => null,
            'question_share' => null,
            'unique_questioners' => 0,
        ];
    }
}
