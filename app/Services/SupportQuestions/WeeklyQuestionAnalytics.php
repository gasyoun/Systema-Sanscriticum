<?php

namespace App\Services\SupportQuestions;

use App\Models\SupportQuestionClassification;
use App\Models\SupportQuestionWeeklySnapshot;
use App\Models\TelegramSupportAccount;
use App\Models\TelegramSupportMessage;
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
        $activity = $this->activity($from, $to, $populations[SupportQuestionClassification::POPULATION_STUDENT]['questions']);

        $payload = [
            'window' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'week_start' => $from->toDateString(),
                'days' => $from->diffInDays($to),
            ],
            'classifier_version' => QuestionMessageClassifier::VERSION,
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
     * классификатора, определении студента и полноте обоих окон; иначе
     * проценты изменения подавляются (возврат null-дельт).
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
                'student_definition' => 'student definition differs',
            ] as $key => $why
        ) {
            if (($current[$key] ?? null) !== ($previous[$key] ?? null)) {
                return ['ready' => false, 'reason' => $why];
            }
        }
        if (! empty($previous['is_incomplete']) || ! empty($current['is_incomplete'])) {
            return ['ready' => false, 'reason' => 'incomplete window'];
        }

        return ['ready' => true, 'reason' => null];
    }

    /**
     * Занятость активных студентов: подтверждённые студенты с lesson_date
     * внутри окна (authoritative denominator); null, когда занятий в окне
     * нет — нормирование недоступно, а не ноль.
     *
     * @return array<string, mixed>
     */
    private function activity(CarbonImmutable $from, CarbonImmutable $to, int $studentQuestions): array
    {
        $activeStudents = (int) DB::table('group_user as gu')
            ->join('lessons as l', 'l.group_id', '=', 'gu.group_id')
            ->whereNull('gu.left_at')
            ->whereBetween('l.lesson_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('count(distinct gu.user_id) as active_students')
            ->value('active_students');

        return [
            'definition' => 'confirmed_students_with_lesson_in_window',
            'active_students' => $activeStudents,
            'questions_per_100_active' => $activeStudents > 0
                ? round($studentQuestions / $activeStudents * 100, 2)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function coverage(Collection $messages, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $incoming = $messages->where('direction', 'incoming');
        $daysWithIncoming = $incoming
            ->map(fn (TelegramSupportMessage $m): string => $m->sent_at->timezone('Europe/Moscow')->toDateString())
            ->unique()
            ->count();

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
            'days_with_incoming' => $daysWithIncoming,
            'days_in_window' => (int) $from->diffInDays($to),
            'chats_active' => $messages->unique('telegram_support_chat_id')->count(),
            'private_chats' => $messages->filter(
                fn (TelegramSupportMessage $m): bool => ($m->chat?->type ?? null) === 'private'
            )->unique('telegram_support_chat_id')->count(),
            'groups' => $messages->filter(
                fn (TelegramSupportMessage $m): bool => in_array($m->chat?->type ?? '', ['group', 'supergroup'], true)
            )->unique('telegram_support_chat_id')->count(),
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
     * @return array{0: bool, 1: string|null}
     */
    private function incompleteness(array $payload): array
    {
        $coverage = $payload['coverage'];
        if (! $coverage['sync']['account_present']) {
            return [true, 'support_account_missing'];
        }
        if ($coverage['sync']['has_error']) {
            return [true, 'sync_error'];
        }
        if (! $coverage['sync']['fresh']) {
            return [true, 'sync_stale'];
        }
        if ($coverage['days_with_incoming'] < $coverage['days_in_window']) {
            return [true, 'incomplete_day_coverage'];
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
