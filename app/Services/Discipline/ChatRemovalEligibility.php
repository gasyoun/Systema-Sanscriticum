<?php

declare(strict_types=1);

namespace App\Services\Discipline;

use App\Enums\ChatRemovalStatus;
use App\Filament\Pages\Debtors;
use App\Models\Course;
use App\Models\CourseDebtChatRemoval;
use App\Models\Group;
use App\Models\PaymentPromise;
use App\Models\User;
use App\Services\DebtorsReport;
use App\Services\Telegram\ZapisiChatMemberService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Правило MG (H2746) в исполняемом виде: КТО подлежит исключению из учебного
 * TG-чата за курсовой долг.
 *
 * Кандидат проходит, если выполнено ВСЁ:
 *   1. он должник по курсу в смысле «Должники» (DebtorsReport — единственный
 *      источник истины по долгу, второй мы не заводим);
 *   2. просрочка ≥ `chat_removal.min_days_overdue` дней;
 *   3. последние `min_unanswered_contacts` контактов остались без ответа;
 *   4. у курса есть активная группа с заполненным `telegram_chat_id`;
 *   5. у студента привязан однозначный `telegram_id`;
 *   6. долг не оспорен и не отсрочен договорённостью;
 *   7. по этому чату нет открытого эпизода в реестре.
 *
 * Чего сервис НЕ делает: не ходит в Telegram, не пишет в реестр, не отправляет
 * сообщений. Он только считает и объясняет. Wave 1 — операторская.
 */
class ChatRemovalEligibility
{
    public const BLOCKER_NOT_OVERDUE_ENOUGH = 'not_overdue_enough';

    public const BLOCKER_INSUFFICIENT_CONTACTS = 'insufficient_contacts';

    public const BLOCKER_STUDENT_RESPONDED = 'student_responded';

    public const BLOCKER_NO_TELEGRAM_LINK = 'no_telegram_link';

    public const BLOCKER_AMBIGUOUS_IDENTITY = 'ambiguous_identity';

    public const BLOCKER_DEBT_DISPUTED = 'debt_disputed';

    public const BLOCKER_ALREADY_OPEN = 'already_open_episode';

    public const BLOCKER_NO_DEBT_AMOUNT = 'debt_amount_unknown';

    public function __construct(
        private readonly DebtorsReport $report,
        private readonly DebtContactEvidenceCollector $evidence,
        private readonly ZapisiChatMemberService $chats,
    ) {}

    public function minDaysOverdue(): int
    {
        return max(1, (int) config('chat_removal.min_days_overdue', 30));
    }

    public function minUnansweredContacts(): int
    {
        return max(1, (int) config('chat_removal.min_unanswered_contacts', 2));
    }

    public function reinstatementFee(): int
    {
        return max(0, (int) config('chat_removal.reinstatement_fee', 1000));
    }

    /**
     * Все тройки (студент, курс, чат) с вердиктом. Опциональные сужения —
     * ровно для операторской проверки одного человека или одного курса.
     *
     * @return Collection<int, ChatRemovalCandidate>
     */
    public function candidates(?int $onlyUserId = null, ?int $onlyCourseId = null, ?Carbon $now = null): Collection
    {
        $now ??= Carbon::now();

        // Debtors::debtBlocks() — канонический расчёт неоплаченных блоков, и
        // второго мы не заводим. Но его статические кэши живут дольше одного
        // прогона (консоль, очередь, тесты), а ключ там — «user_id:course_id».
        // Сбрасываем перед проходом: отчёт, посчитанный по чужим платежам,
        // хуже медленного.
        Debtors::flushPairCaches();

        $titles = $this->report->courseTitles();
        $refBlocks = $this->report->referenceBlocks();

        $rows = $this->report->query()
            ->when($onlyUserId !== null, fn ($q) => $q->where('users.id', $onlyUserId))
            ->when($onlyCourseId !== null, fn ($q) => $q->where('d.course_id', $onlyCourseId))
            ->get();

        $out = collect();

        foreach ($rows as $row) {
            $userId = (int) $row->id;
            $courseId = (int) $row->course_id;
            // Корневая модель отчёта — User (joinSub с полями долга поверх),
            // так что строка УЖЕ является User. Повторный User::find() здесь
            // стоил бы одного лишнего запроса на каждого должника.
            $user = $row;

            $refNumber = $row->ref_block_number !== null ? (int) $row->ref_block_number : null;
            $daysOverdue = $refNumber !== null
                ? $this->report->daysOverdueFor($courseId, $refNumber)
                : 0;

            // Эпизод долга начинается со старта reference-блока: контакты и
            // ответы прошлого года к сегодняшней просрочке отношения не имеют.
            $episodeSince = $refBlocks->get($courseId)?->starts_at;
            $episodeSince = $episodeSince !== null ? Carbon::parse($episodeSince) : null;

            $evidence = $this->evidence->collect($user, $courseId, $episodeSince, $now);

            $debtBlocks = $refNumber !== null
                ? Debtors::debtBlocks($userId, $courseId, $refNumber)
                : [];
            $debtInfo = $this->report->computeDebtAmount($user, $courseId, $debtBlocks);

            $chats = $this->chats->studyGroupsWithChat($user, $courseId);
            $baseBlockers = $this->baseBlockers($user, $courseId, $daysOverdue, $evidence, $debtInfo);

            if ($chats->isEmpty()) {
                // Нет чата — нечего и исключать; строку всё равно показываем,
                // иначе оператор не поймёт, почему должник «пропал» из отчёта.
                $out->push(new ChatRemovalCandidate(
                    user: $user,
                    courseId: $courseId,
                    courseTitle: (string) ($titles[$courseId] ?? Course::find($courseId)?->title ?? '—'),
                    group: null,
                    telegramChatId: '',
                    daysOverdue: $daysOverdue,
                    debtAmount: $debtInfo['amount'],
                    debtBlockNumbers: $debtBlocks,
                    referenceBlock: $refNumber,
                    evidence: $evidence,
                    blockers: array_values(array_unique([...$baseBlockers, 'no_study_chat'])),
                    reinstatementFee: $this->reinstatementFee(),
                    episodeSince: $episodeSince,
                ));

                continue;
            }

            foreach ($chats as $group) {
                $chatId = trim((string) $group->telegram_chat_id);
                $blockers = $baseBlockers;

                if ($this->hasOpenEpisode($userId, $chatId)) {
                    $blockers[] = self::BLOCKER_ALREADY_OPEN;
                }

                $out->push(new ChatRemovalCandidate(
                    user: $user,
                    courseId: $courseId,
                    courseTitle: (string) ($titles[$courseId] ?? Course::find($courseId)?->title ?? '—'),
                    group: $group instanceof Group ? $group : null,
                    telegramChatId: $chatId,
                    daysOverdue: $daysOverdue,
                    debtAmount: $debtInfo['amount'],
                    debtBlockNumbers: $debtBlocks,
                    referenceBlock: $refNumber,
                    evidence: $evidence,
                    blockers: array_values(array_unique($blockers)),
                    reinstatementFee: $this->reinstatementFee(),
                    episodeSince: $episodeSince,
                ));
            }
        }

        return $out->values();
    }

    /**
     * Причины, не зависящие от конкретного чата.
     *
     * @param  array{amount: ?float, missing_tariffs: int}  $debtInfo
     * @return list<string>
     */
    private function baseBlockers(
        User $user,
        int $courseId,
        int $daysOverdue,
        ContactEvidence $evidence,
        array $debtInfo,
    ): array {
        $blockers = [];

        if ($daysOverdue < $this->minDaysOverdue()) {
            $blockers[] = self::BLOCKER_NOT_OVERDUE_ENOUGH;
        }

        if ($evidence->attemptCount() < $this->minUnansweredContacts()) {
            $blockers[] = self::BLOCKER_INSUFFICIENT_CONTACTS;
        } elseif ($evidence->trailingUnanswered < $this->minUnansweredContacts()) {
            // Контактов хватает, но студент на связи — это уже не «молчание».
            $blockers[] = self::BLOCKER_STUDENT_RESPONDED;
        }

        $telegramId = trim((string) ($user->telegram_id ?? ''));
        if ($telegramId === '') {
            $blockers[] = self::BLOCKER_NO_TELEGRAM_LINK;
        } elseif ($this->telegramIdIsShared($telegramId, (int) $user->id)) {
            // Один telegram_id на двух учётках — плательщик неоднозначен;
            // «остановиться при неоднозначной личности плательщика» (guardrail).
            $blockers[] = self::BLOCKER_AMBIGUOUS_IDENTITY;
        }

        if ($this->debtIsDisputed((int) $user->id, $courseId)) {
            $blockers[] = self::BLOCKER_DEBT_DISPUTED;
        }

        // Сумму долга не собрали (нет тарифов блоков) — исключать за сумму,
        // которую мы не умеем назвать, нельзя: взнос и претензия повиснут.
        if ($debtInfo['amount'] === null || ($debtInfo['missing_tariffs'] ?? 0) > 0) {
            $blockers[] = self::BLOCKER_NO_DEBT_AMOUNT;
        }

        return $blockers;
    }

    /**
     * Долг «под договорённостью»: есть непогашенное обещание оплаты (в т.ч.
     * рассрочка). Куратор уже дал срок — исключать до его конца нельзя.
     */
    private function debtIsDisputed(int $userId, int $courseId): bool
    {
        return PaymentPromise::query()
            ->forPair($userId, $courseId)
            ->where('status', PaymentPromise::STATUS_ACTIVE)
            ->exists();
    }

    private function telegramIdIsShared(string $telegramId, int $userId): bool
    {
        return User::query()
            ->where('telegram_id', $telegramId)
            ->whereKeyNot($userId)
            ->exists();
    }

    private function hasOpenEpisode(int $userId, string $chatId): bool
    {
        return CourseDebtChatRemoval::query()
            ->open()
            ->where('user_id', $userId)
            ->where('telegram_chat_id', $chatId)
            ->exists();
    }

    /**
     * Сколько взнос за возврат этого студента: ₽fee × число чатов, из которых
     * его исключили за неоплату и молчание и куда он ещё не вернулся.
     * Ровно та арифметика, которую MG озвучил, — без скидок за «оптом».
     *
     * @return array{chats: int, amount: int}
     */
    public function outstandingFeeFor(int $userId): array
    {
        $rows = CourseDebtChatRemoval::query()
            ->feeOutstanding()
            ->where('user_id', $userId)
            ->whereNotNull('removed_at')
            ->get(['reinstatement_fee']);

        return [
            'chats' => $rows->count(),
            'amount' => (int) $rows->sum(fn ($r) => (float) $r->reinstatement_fee),
        ];
    }

    /** Человекочитаемая расшифровка блокера — для отчёта и админки. */
    public static function blockerLabel(string $blocker): string
    {
        return match ($blocker) {
            self::BLOCKER_NOT_OVERDUE_ENOUGH => 'просрочка меньше порога',
            self::BLOCKER_INSUFFICIENT_CONTACTS => 'меньше двух зафиксированных контактов',
            self::BLOCKER_STUDENT_RESPONDED => 'студент отвечал — это не молчание',
            self::BLOCKER_NO_TELEGRAM_LINK => 'не привязан telegram_id',
            self::BLOCKER_AMBIGUOUS_IDENTITY => 'telegram_id встречается у нескольких учёток',
            self::BLOCKER_DEBT_DISPUTED => 'действует договорённость об оплате',
            self::BLOCKER_ALREADY_OPEN => 'по этому чату уже открыт эпизод',
            self::BLOCKER_NO_DEBT_AMOUNT => 'сумма долга не посчитана (нет тарифов блоков)',
            'no_study_chat' => 'у курса нет группы с telegram_chat_id',
            default => $blocker,
        };
    }

    /** @return array<string, string> статус эпизода → подпись */
    public static function statusLabels(): array
    {
        $out = [];
        foreach (ChatRemovalStatus::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
