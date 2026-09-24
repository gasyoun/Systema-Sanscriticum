<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\MoneyAllocation;
use App\Models\MoneyMovement;
use App\Models\MoneyObligation;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * H5443 (P1): единственная точка записи в денежное ядро.
 *
 * Правила (docs/DECISIONS_MONEY_LEDGER_AND_RECONCILIATION_2026.md):
 *  - D2/D9  движения и распределения только добавляются; исправление —
 *           связанное сторно + корректировка, итог = оригинал + сторно + новая;
 *  - D6/D7  цена обязательства = договорная − скидка, депозит зачитывается
 *           после как уже внесённые деньги; пробное признаётся по факту;
 *  - D12    сумма возвратов не выше исходной оплаты; компенсация — отдельный тип;
 *  - D16    прямой платёж преподавателю = платёж студента + погашение
 *           обязательства перед этим преподавателем, доказательство — один раз.
 *
 * Каждое правило продублировано триггером БД (миграция 2026_09_24_150000);
 * сервис добавляет блокировки (сериализация конкурентных возвратов и
 * распределений), идемпотентный повтор по ключу и понятные сообщения.
 * Все суммы — целые копейки.
 *
 * P1 не переключает ни одного читателя: запись вне теневого прогона
 * требует флага features.money_ledger_core (по умолчанию выключен).
 */
final class LedgerService
{
    /** Поля, по которым повтор с тем же ключом признаётся тем же фактом. */
    private const REPLAY_FIELDS = [
        'type', 'amount_kopecks', 'user_id', 'course_id', 'teacher_id', 'root_movement_id',
        'reverses_movement_id', 'corrects_movement_id', 'refund_of_movement_id', 'pairs_movement_id',
        'cap_anchor_id', 'evidence_key', 'source_currency', 'source_amount_minor', 'received_account',
    ];

    private const OPTS = [
        'evidence_key', 'source_currency', 'source_amount_minor', 'legacy_payment_id',
        'legacy_teacher_payout_id', 'created_by', 'reason', 'meta', 'approved_by',
    ];

    private const CONCURRENCY_ATTEMPTS = 3;

    /** Статический: модели проверяют флаг через новый экземпляр сервиса. */
    /** Сторно, открытое корректировкой `<ключ>`, получает ключ `<ключ>:reversal`; вне correct() суффикс запрещён. */
    public const CORRECTION_REVERSAL_SUFFIX = ':reversal';

    private static int $shadowDepth = 0;

    /** @var list<int> уровни транзакций активных теневых прогонов */
    private static array $shadowLevels = [];

    private static bool $shadowEscaped = false;

    private static ?object $guardedDispatcher = null;

    public function __construct(private readonly LedgerProjection $projection) {}

    // ------------------------------------------------------------------
    // Флаг и теневой прогон
    // ------------------------------------------------------------------

    public function writable(): bool
    {
        return self::$shadowDepth > 0 || (bool) config('features.money_ledger_core');
    }

    /**
     * Теневой прогон: $fn пишет в ядро внутри транзакции, которая ВСЕГДА
     * откатывается. Используется отчётом бэкфилла, чтобы проверить легаси-
     * данные настоящими триггерами, не оставив ни одной строки.
     *
     * @template T
     *
     * @param  Closure(self): T  $fn
     * @return T
     */
    public function shadow(Closure $fn): mixed
    {
        self::guardShadowCommits();
        DB::beginTransaction();
        $level = DB::transactionLevel();
        self::$shadowDepth++;
        self::$shadowLevels[] = $level;
        $escapedBefore = self::$shadowEscaped;
        self::$shadowEscaped = false;
        try {
            $result = $fn($this);
            if (self::$shadowEscaped || DB::transactionLevel() !== $level) {
                throw new LedgerInvariantViolation('ledger: shadow run left its transaction level — a commit inside shadow() is refused');
            }

            return $result;
        } finally {
            self::$shadowDepth--;
            array_pop(self::$shadowLevels);
            self::$shadowEscaped = $escapedBefore;
            while (DB::transactionLevel() >= $level) {
                DB::rollBack();
            }
            // Враждебный колбэк мог откатить уровни сам, а отбитый COMMIT — сбросить счётчик
            // Laravel без отката в PDO: открытая транзакция без уровня откатывается здесь.
            $pdo = DB::connection()->getPdo();
            if (DB::transactionLevel() === 0 && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * Настоящий COMMIT (уровень 1) внутри теневого прогона отбивается ДО записи
     * на диск; коммит вложенного уровня ниже уровня прогона помечается, и прогон
     * бросает исключение — вызывающая транзакция откатывается целиком.
     */
    private static function guardShadowCommits(): void
    {
        $events = app('events');
        if (self::$guardedDispatcher === $events) {
            return;
        }
        self::$guardedDispatcher = $events;
        Event::listen(TransactionCommitting::class, function (): void {
            if (self::$shadowDepth > 0) {
                throw new LedgerInvariantViolation('ledger: a commit inside shadow() is refused');
            }
        });
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $e): void {
            if (self::$shadowLevels !== [] && $e->connection->transactionLevel() < min(self::$shadowLevels)) {
                self::$shadowEscaped = true;
            }
        });
    }

    // ------------------------------------------------------------------
    // Движения
    // ------------------------------------------------------------------

    /** Поступление денег студента в кассу школы. */
    public function receipt(string $key, int $kopecks, int $userId, ?int $courseId, CarbonInterface $occurredAt, array $opts = []): MoneyMovement
    {
        $this->positive($kopecks);

        return $this->write(fn () => $this->insertMovement($key, [
            'type' => MoneyMovement::RECEIPT,
            'amount_kopecks' => $kopecks,
            'user_id' => $userId,
            'course_id' => $courseId,
            'received_account' => MoneyMovement::ACCOUNT_SCHOOL,
            'source_currency' => $opts['source_currency'] ?? 'RUB',
            'occurred_at' => $occurredAt,
        ], $opts));
    }

    /**
     * D16: платёж студента напрямую преподавателю — два связанных факта:
     * поступление (без движения по счёту школы) и погашение обязательства
     * школы перед этим преподавателем. Доказательство потребляется один раз.
     *
     * @return array{0: MoneyMovement, 1: MoneyMovement} [поступление, погашение]
     */
    public function directTeacherReceipt(
        string $key,
        int $kopecks,
        int $userId,
        ?int $courseId,
        int $teacherId,
        string $currency,
        int $sourceAmountMinor,
        string $evidenceKey,
        CarbonInterface $occurredAt,
        array $opts = [],
    ): array {
        $this->positive($kopecks);

        return $this->write(function () use ($key, $kopecks, $userId, $courseId, $teacherId, $currency, $sourceAmountMinor, $evidenceKey, $occurredAt, $opts) {
            $receipt = $this->insertMovement($key, [
                'type' => MoneyMovement::DIRECT_TEACHER_RECEIPT,
                'amount_kopecks' => $kopecks,
                'user_id' => $userId,
                'course_id' => $courseId,
                'teacher_id' => $teacherId,
                'received_account' => MoneyMovement::ACCOUNT_TEACHER_PERSONAL,
                'source_currency' => strtoupper($currency),
                'source_amount_minor' => $sourceAmountMinor,
                'evidence_key' => $evidenceKey,
                'occurred_at' => $occurredAt,
            ], array_diff_key($opts, ['evidence_key' => 1, 'source_currency' => 1, 'source_amount_minor' => 1]));

            $offset = $this->insertMovement($key.':teacher-offset', [
                'type' => MoneyMovement::PAYOUT,
                'amount_kopecks' => -$kopecks,
                'user_id' => $userId,
                'course_id' => $courseId,
                'teacher_id' => $teacherId,
                'pairs_movement_id' => $receipt->id,
                'occurred_at' => $occurredAt,
                'reason' => 'D16: погашение обязательства перед преподавателем прямым платежом студента',
                'meta' => ['channel' => 'direct_offset'],
            ], ['created_by' => $opts['created_by'] ?? null]);

            return [$receipt, $offset];
        });
    }

    /**
     * D16: ошибочный прямой платёж преподавателю сторнируется и записывается заново.
     * Доказательство исходного платежа остаётся за сторнированной строкой; повторная
     * запись получает производный ключ `rerecord:<id>:<sha1>` — ровно один на
     * сторнированный оригинал, так что одно подтверждение не живёт дважды.
     * Студент тот же (доказательство — его платёж); сумма, валюта, курс и преподаватель
     * могут быть исправлены.
     *
     * @param  array{teacher_id?: int, course_id?: int|null}  $overrides
     * @return array{0: MoneyMovement, 1: MoneyMovement}
     */
    public function rerecordDirectTeacherReceipt(MoneyMovement $reversed, string $key, int $kopecks, string $currency, int $sourceAmountMinor, CarbonInterface $occurredAt, array $overrides = [], array $opts = []): array
    {
        $this->positive($kopecks);

        return $this->write(function () use ($reversed, $key, $kopecks, $currency, $sourceAmountMinor, $occurredAt, $overrides, $opts) {
            $orig = $this->lockMovement($reversed->id);
            if ($orig->type !== MoneyMovement::DIRECT_TEACHER_RECEIPT || $this->reversalOf($orig) === null) {
                throw new LedgerInvariantViolation('ledger: only a reversed direct teacher receipt is re-recorded');
            }

            return $this->directTeacherReceipt(
                $key,
                $kopecks,
                (int) $orig->user_id,
                array_key_exists('course_id', $overrides) ? $overrides['course_id'] : $orig->course_id,
                (int) ($overrides['teacher_id'] ?? $orig->teacher_id),
                $currency,
                $sourceAmountMinor,
                self::rerecordEvidenceKey($orig),
                $occurredAt,
                $opts,
            );
        });
    }

    public static function rerecordEvidenceKey(MoneyMovement $reversed): string
    {
        return 'rerecord:'.$reversed->id.':'.sha1((string) $reversed->evidence_key);
    }

    /**
     * D12: возврат по исходной оплате, не выше её доступного остатка.
     * $fromObligations — явное уменьшение обязательств [obligation_id => копейки]
     * (D10; обязательность для частичного возврата включает P3).
     *
     * @param  array<int, int>  $fromObligations
     */
    public function refund(MoneyMovement $source, string $key, int $kopecks, CarbonInterface $occurredAt, array $fromObligations = [], array $opts = []): MoneyMovement
    {
        $this->positive($kopecks);

        return $this->write(function () use ($source, $key, $kopecks, $occurredAt, $fromObligations, $opts) {
            $src = $this->lockMovement($source->id);
            if (! $src->isInflowRoot()) {
                throw new LedgerInvariantViolation('ledger: source payment must be a root receipt');
            }

            $refund = $this->insertMovement($key, [
                'type' => MoneyMovement::REFUND,
                'amount_kopecks' => -$kopecks,
                'user_id' => $src->user_id,
                'course_id' => $src->course_id,
                'refund_of_movement_id' => $src->id,
                'cap_anchor_id' => $src->id,
                'occurred_at' => $occurredAt,
            ], $opts, precheck: function () use ($src, $kopecks, $fromObligations): void {
                $remaining = $this->projection->refundableRemaining($src, forUpdate: true);
                if ($kopecks > $remaining) {
                    throw new LedgerInvariantViolation("ledger: refunds exceed the source payment (remaining {$remaining}, asked {$kopecks})");
                }
                // Деньги, уже закрывающие обязательства, уходят только с явным их уменьшением.
                $free = $this->projection->familyResidue($src->id, forUpdate: true);
                $unnamed = $kopecks - array_sum($fromObligations);
                if ($unnamed > $free) {
                    throw new LedgerInvariantViolation("ledger: refund exceeds the unallocated residue — name the obligations it reduces (free {$free}, unnamed {$unnamed})");
                }
            });

            foreach ($fromObligations as $obligationId => $part) {
                $this->allocateLocked($refund, $this->lockObligation((int) $obligationId), $part, $key.':from:'.$obligationId, 'D10: возврат уменьшает обязательство', $opts['created_by'] ?? null);
            }

            return $refund;
        });
    }

    /** D12: выплата студенту сверх возврата — отдельная операция с основанием и согласованием. */
    public function compensation(string $key, int $kopecks, int $userId, ?int $courseId, string $reason, int $approvedBy, CarbonInterface $occurredAt, ?MoneyMovement $source = null, array $opts = []): MoneyMovement
    {
        $this->positive($kopecks);

        return $this->write(fn () => $this->insertMovement($key, [
            'type' => MoneyMovement::COMPENSATION,
            'amount_kopecks' => -$kopecks,
            'user_id' => $userId,
            'course_id' => $courseId,
            'refund_of_movement_id' => $source?->id,
            'reason' => $reason,
            'approved_by' => $approvedBy,
            'occurred_at' => $occurredAt,
        ], $opts));
    }

    /** Выплата преподавателю (пакеты выплат строит P2 поверх этого движения). */
    public function payout(string $key, int $kopecks, int $teacherId, CarbonInterface $occurredAt, array $opts = []): MoneyMovement
    {
        $this->positive($kopecks);

        return $this->write(fn () => $this->insertMovement($key, [
            'type' => MoneyMovement::PAYOUT,
            'amount_kopecks' => -$kopecks,
            'teacher_id' => $teacherId,
            'occurred_at' => $occurredAt,
        ], $opts));
    }

    /**
     * D9: связанное сторно. Снимает живые распределения строки (в обратном
     * порядке, чтобы ни одно обязательство не ушло за 0..цена), а сторно
     * прямого платежа преподавателю сторнирует и его погашение.
     */
    public function reverse(MoneyMovement $movement, string $key, string $reason, ?int $by = null, ?CarbonInterface $occurredAt = null): MoneyMovement
    {
        if (str_ends_with($key, self::CORRECTION_REVERSAL_SUFFIX)) {
            throw new LedgerInvariantViolation('ledger: the key suffix :reversal is reserved for the reversal half of a correction');
        }

        return $this->write(function () use ($movement, $key, $reason, $by, $occurredAt) {
            $this->lockFamily($movement);
            $m = MoneyMovement::query()->findOrFail($movement->id);
            if ($m->type === MoneyMovement::REVERSAL) {
                throw new LedgerInvariantViolation('ledger: a reversal is never reversed — post a correction instead');
            }

            $done = $this->reversalOf($m);
            if ($done !== null) {
                if ($done->movement_key === $key) {
                    return $done;
                }
                throw new LedgerInvariantViolation('ledger: movement already reversed');
            }

            if ($m->pairs_movement_id !== null) {
                throw new LedgerInvariantViolation('ledger: a direct-receipt offset is reversed only together with its receipt — reverse the receipt');
            }

            $reversal = $this->reverseLocked($m, $key, $reason, $by, $occurredAt);

            // D16: погашение сторнируется после своего платежа (триггер не даст раньше).
            if ($m->type === MoneyMovement::DIRECT_TEACHER_RECEIPT) {
                $offset = MoneyMovement::query()->where('pairs_movement_id', $m->id)->first();
                if ($offset !== null && $this->reversalOf($offset) === null) {
                    $this->reverseLocked($offset, $key.':teacher-offset', $reason, $by, $occurredAt);
                }
            }

            return $reversal;
        });
    }

    /**
     * D9: корректировка = новая строка с правильной суммой + сторно исходной.
     * Порядок вставки выбран так, чтобы промежуточное состояние не нарушало
     * предел возврата: для притока сначала корректировка, для оттока — сторно.
     * Распределения исходной строки снимаются сторно. Для притока они тут же
     * переносятся на корректировку (проведённые занятия первыми) в пределах
     * новой суммы — иначе «корректировка на ту же сумму + возврат» вынула бы
     * деньги проведённого занятия. Отток корректировки вызывающий распределяет
     * сам через allocate().
     *
     * @return array{0: MoneyMovement, 1: MoneyMovement} [корректировка, сторно]
     */
    public function correct(MoneyMovement $movement, string $key, int $newKopecks, string $reason, ?int $by = null, ?CarbonInterface $occurredAt = null): array
    {
        $this->positive($newKopecks);
        if (strlen($key) > 191 - strlen(self::CORRECTION_REVERSAL_SUFFIX)) {
            throw new LedgerInvariantViolation('ledger: a correction key must leave room for its :reversal half (at most 182 bytes)');
        }

        return $this->write(function () use ($movement, $key, $newKopecks, $reason, $by, $occurredAt) {
            $this->lockFamily($movement);
            $m = MoneyMovement::query()->findOrFail($movement->id);
            if ($m->type === MoneyMovement::REVERSAL) {
                throw new LedgerInvariantViolation('ledger: correct the original row, not its reversal');
            }
            $root = $m->isRoot() ? $m : MoneyMovement::query()->findOrFail($m->root_movement_id);
            if ($root->type === MoneyMovement::DIRECT_TEACHER_RECEIPT || $root->pairs_movement_id !== null) {
                throw new LedgerInvariantViolation('ledger: a direct teacher receipt is reversed and re-recorded, never corrected (its offset mirrors it)');
            }

            $existingReversal = $this->reversalOf($m);
            if ($existingReversal !== null && $existingReversal->movement_key !== $key.':reversal') {
                throw new LedgerInvariantViolation('ledger: a reversed row is not corrected — it is already void; record a new posting instead');
            }

            $inflow = in_array($root->type, MoneyMovement::INFLOW_TYPES, true);
            $attrs = [
                'type' => MoneyMovement::CORRECTION,
                'amount_kopecks' => $inflow ? $newKopecks : -$newKopecks,
                'user_id' => $m->user_id,
                'course_id' => $m->course_id,
                'teacher_id' => $m->teacher_id,
                'root_movement_id' => $root->id,
                'corrects_movement_id' => $m->id,
                'cap_anchor_id' => $this->expectedAnchor($root),
                'occurred_at' => $occurredAt ?? now(),
                'reason' => $reason,
                'created_by' => $by,
            ];

            if ($inflow) {
                $correction = $this->insertMovement($key, $attrs);
                $reversal = $this->reversalOf($m) ?? $this->reverseLocked($m, $key.':reversal', $reason, $by, $occurredAt);
                if ($reversal->movement_key === $key.':reversal') {
                    $this->carryAllocations($reversal, $correction, $key, $by);
                }
            } else {
                $reversal = $this->reversalOf($m) ?? $this->reverseLocked($m, $key.':reversal', $reason, $by, $occurredAt);
                $correction = $this->insertMovement($key, $attrs);
                if ($reversal->movement_key === $key.':reversal') {
                    $this->carryAllocations($reversal, $correction, $key, $by);
                }
            }

            $residue = $this->projection->familyResidue((int) $correction->cap_anchor_id, forUpdate: true);
            if ($residue < 0) {
                throw new LedgerInvariantViolation("ledger: a correction cannot leave its payment family holding less than it has allocated (short by {$this->abs($residue)})");
            }

            return [$correction, $reversal];
        });
    }

    // ------------------------------------------------------------------
    // Распределения
    // ------------------------------------------------------------------

    /** Распределить $kopecks движения на обязательство (знак берётся от движения). */
    public function allocate(MoneyMovement $movement, MoneyObligation $obligation, int $kopecks, string $key, ?string $reason = null, ?int $by = null): MoneyAllocation
    {
        return $this->write(function () use ($movement, $obligation, $kopecks, $key, $reason, $by) {
            $m = $this->lockMovement($movement->id);

            return $this->allocateLocked($m, $this->lockObligation($obligation->id), $kopecks, $key, $reason, $by);
        });
    }

    /** Обратное распределение (снять ранее распределённое), по умолчанию на том же движении. */
    public function deallocate(MoneyAllocation $allocation, string $key, ?MoneyMovement $via = null, ?string $reason = null, ?int $by = null): MoneyAllocation
    {
        return $this->write(function () use ($allocation, $key, $via, $reason, $by) {
            $this->lockObligation($allocation->obligation_id);
            $target = MoneyMovement::query()->findOrFail($allocation->movement_id);
            $carrier = $via === null ? $target : MoneyMovement::query()->findOrFail($via->id);

            if ($carrier->id !== $target->id) {
                $oppositeSign = ($carrier->amount_kopecks > 0) !== ($target->amount_kopecks > 0);
                $sameChain = $carrier->chainRootId() === $target->chainRootId();
                $refundOfChain = $carrier->type === MoneyMovement::REFUND && $carrier->refund_of_movement_id === $target->chainRootId();
                if (! $oppositeSign || ! ($sameChain || $refundOfChain)) {
                    throw new LedgerInvariantViolation('ledger: allocation reversal must ride the target movement, its chain or a refund of it');
                }
                if ($carrier->type === MoneyMovement::REVERSAL && $carrier->reverses_movement_id !== $target->id) {
                    throw new LedgerInvariantViolation('ledger: a reversal carries only the mirrors of the row it reverses');
                }
            }

            return $this->insertAllocation($key, [
                'movement_id' => $carrier->id,
                'obligation_id' => $allocation->obligation_id,
                'amount_kopecks' => -$allocation->amount_kopecks,
                'reverses_allocation_id' => $allocation->id,
                'reason' => $reason,
                'created_by' => $by,
            ]);
        });
    }

    /**
     * D7: зачесть депозит в обязательство. Цена обязательства уже уменьшена
     * скидкой; депозит работает как ранее внесённые деньги — пара
     * распределений на тех же движениях, без фиктивного денежного движения.
     *
     * @return list<MoneyAllocation>
     */
    public function applyDeposit(MoneyObligation $deposit, MoneyObligation $target, int $kopecks, string $key, ?int $by = null): array
    {
        $this->positive($kopecks);

        return $this->write(function () use ($deposit, $target, $kopecks, $key, $by) {
            foreach (collect([$deposit->id, $target->id])->sort() as $id) {
                $this->lockObligation($id);
            }
            $deposit = MoneyObligation::query()->findOrFail($deposit->id);
            $target = MoneyObligation::query()->findOrFail($target->id);

            if ($deposit->kind !== MoneyObligation::DEPOSIT) {
                throw new LedgerInvariantViolation('ledger: deposit application must draw from a deposit obligation');
            }
            if ($target->kind === MoneyObligation::DEPOSIT || $deposit->user_id !== $target->user_id) {
                throw new LedgerInvariantViolation('ledger: deposit applies only to the same student’s lesson or debt obligation');
            }

            $done = MoneyAllocation::query()->where('transfer_group', $key)->orderBy('id')->get();
            if ($done->isNotEmpty()) {
                $applied = (int) $done->where('obligation_id', $target->id)->sum('amount_kopecks');
                if ($applied !== $kopecks) {
                    throw new LedgerReplayConflict("ledger: deposit application {$key} was already applied with {$applied}, not {$kopecks}");
                }

                return $done->all();
            }

            $room = $target->price_kopecks - $this->projection->obligationAllocated($target->id, forUpdate: true);
            if ($kopecks > $room) {
                throw new LedgerInvariantViolation("ledger: deposit exceeds the obligation price after discount (room {$room}, asked {$kopecks})");
            }

            // Держит депозит СЕМЬЯ оплаты (оплата + её возвраты, сторно,
            // корректировки); переносит — живая, не сторнированная строка семьи.
            $rows = DB::table('money_allocations as a')
                ->join('money_movements as m', 'm.id', '=', 'a.movement_id')
                ->where('a.obligation_id', $deposit->id)
                ->groupBy('a.movement_id', 'm.cap_anchor_id', 'm.type')
                ->orderBy('a.movement_id')
                ->selectRaw('a.movement_id, COALESCE(m.cap_anchor_id, a.movement_id) AS family, m.type, SUM(a.amount_kopecks) AS held')
                ->get();
            $familyLeft = [];
            foreach ($rows as $row) {
                $familyLeft[(int) $row->family] = ($familyLeft[(int) $row->family] ?? 0) + (int) $row->held;
            }
            $reversed = MoneyMovement::query()->whereIn('reverses_movement_id', $rows->pluck('movement_id'))->pluck('reverses_movement_id')->all();
            $balances = $rows->reject(fn ($r) => $r->type === MoneyMovement::REVERSAL || in_array($r->movement_id, $reversed, false))->values();

            $left = $kopecks;
            $made = [];
            foreach ($balances as $i => $row) {
                $take = min((int) $row->held, $familyLeft[(int) $row->family], $left);
                $familyLeft[(int) $row->family] -= max(0, $take);
                if ($take <= 0) {
                    continue;
                }
                $base = ['movement_id' => (int) $row->movement_id, 'transfer_group' => $key, 'created_by' => $by];
                $made[] = $this->insertAllocation("{$key}:{$i}:out", $base + ['obligation_id' => $deposit->id, 'amount_kopecks' => -$take, 'reason' => 'D7: зачёт депозита']);
                $made[] = $this->insertAllocation("{$key}:{$i}:in", $base + ['obligation_id' => $target->id, 'amount_kopecks' => $take, 'reason' => 'D7: зачёт депозита']);
                $left -= $take;
                if ($left === 0) {
                    break;
                }
            }

            if ($left > 0) {
                throw new LedgerInvariantViolation("ledger: deposit holds less than asked (short by {$left})");
            }

            return $made;
        });
    }

    // ------------------------------------------------------------------
    // Обязательства
    // ------------------------------------------------------------------

    /** Блок из четырёх занятий: цена = договорная − скидка (D6, D7). */
    public function openBlock(string $key, int $userId, int $courseId, int $blockNumber, int $listKopecks, int $discountKopecks = 0, array $opts = []): MoneyObligation
    {
        return $this->openObligation($key, MoneyObligation::BLOCK, $userId, $courseId, $blockNumber, MoneyObligation::LESSONS_PER_BLOCK, $listKopecks, $discountKopecks, $opts);
    }

    /** Пробное занятие — признаётся только после проведения (D6). */
    public function openTrial(string $key, int $userId, ?int $courseId, int $listKopecks, int $discountKopecks = 0, array $opts = []): MoneyObligation
    {
        return $this->openObligation($key, MoneyObligation::TRIAL, $userId, $courseId, null, 1, $listKopecks, $discountKopecks, $opts);
    }

    /** Депозит — обязательство школы до зачёта, не выручка (D6). */
    public function openDeposit(string $key, int $userId, ?int $courseId, int $kopecks, array $opts = []): MoneyObligation
    {
        return $this->openObligation($key, MoneyObligation::DEPOSIT, $userId, $courseId, null, null, $kopecks, 0, $opts);
    }

    /** Долг, не привязанный к блоку (начальные остатки P4). */
    public function openDebt(string $key, int $userId, ?int $courseId, int $kopecks, array $opts = []): MoneyObligation
    {
        return $this->openObligation($key, MoneyObligation::DEBT, $userId, $courseId, null, null, $kopecks, 0, $opts);
    }

    /** Факт проведения занятия/блока. Write-once; повтор с той же датой — no-op. */
    public function markDelivered(MoneyObligation $obligation, CarbonInterface $at): MoneyObligation
    {
        return $this->write(function () use ($obligation, $at) {
            $o = $this->lockObligation($obligation->id);
            if (! $o->isLessonObligation()) {
                throw new LedgerInvariantViolation('ledger: only a lesson obligation can be delivered');
            }
            if ($at->greaterThan(now())) {
                throw new LedgerInvariantViolation('ledger: a lesson is delivered only after it happened (D6), not in the future');
            }
            if ($o->delivered_at !== null) {
                if ($o->delivered_at->equalTo($at)) {
                    return $o;
                }
                throw new LedgerInvariantViolation('ledger: delivered_at is write-once');
            }
            $this->translate(fn () => $o->update(['delivered_at' => $at]));

            return $o;
        });
    }

    public function cancel(MoneyObligation $obligation, CarbonInterface $at): MoneyObligation
    {
        return $this->write(function () use ($obligation, $at) {
            $o = $this->lockObligation($obligation->id);
            if ($o->cancelled_at !== null) {
                return $o;
            }
            $this->translate(fn () => $o->update(['cancelled_at' => $at]));

            return $o;
        });
    }

    // ------------------------------------------------------------------
    // Внутреннее
    // ------------------------------------------------------------------

    private function openObligation(string $key, string $kind, int $userId, ?int $courseId, ?int $blockNumber, ?int $lessons, int $listKopecks, int $discountKopecks, array $opts): MoneyObligation
    {
        $attrs = [
            'kind' => $kind,
            'user_id' => $userId,
            'course_id' => $courseId,
            'block_number' => $blockNumber,
            'lessons_count' => $lessons,
            'list_price_kopecks' => $listKopecks,
            'discount_kopecks' => $discountKopecks,
            'price_kopecks' => $listKopecks - $discountKopecks,
            'legacy_payment_id' => $opts['legacy_payment_id'] ?? null,
            'reason' => $opts['reason'] ?? null,
            'created_by' => $opts['created_by'] ?? null,
            'meta' => $opts['meta'] ?? null,
        ];

        return $this->write(function () use ($key, $attrs) {
            $existing = MoneyObligation::query()->where('obligation_key', $key)->first();
            if ($existing !== null) {
                foreach (['kind', 'user_id', 'course_id', 'block_number', 'list_price_kopecks', 'discount_kopecks'] as $f) {
                    if ($existing->{$f} != $attrs[$f]) {
                        throw new LedgerReplayConflict("ledger: obligation {$key} replayed with different {$f}");
                    }
                }

                return $existing;
            }

            return $this->translate(fn () => MoneyObligation::query()->create(['obligation_key' => $key] + $attrs));
        });
    }

    private function reverseLocked(MoneyMovement $m, string $key, string $reason, ?int $by, ?CarbonInterface $occurredAt): MoneyMovement
    {
        $reversal = $this->insertMovement($key, [
            'type' => MoneyMovement::REVERSAL,
            'amount_kopecks' => -$m->amount_kopecks,
            'user_id' => $m->user_id,
            'course_id' => $m->course_id,
            'teacher_id' => $m->teacher_id,
            'root_movement_id' => $m->chainRootId(),
            'reverses_movement_id' => $m->id,
            'cap_anchor_id' => $this->expectedAnchor($m),
            'occurred_at' => $occurredAt ?? now(),
            'reason' => $reason,
            'created_by' => $by,
        ]);

        // Снять живые распределения исходной строки в обратном порядке.
        $live = MoneyAllocation::query()
            ->where('movement_id', $m->id)
            ->whereNull('reverses_allocation_id')
            ->whereNotIn('id', MoneyAllocation::query()->whereNotNull('reverses_allocation_id')->select('reverses_allocation_id'))
            ->orderByDesc('id')
            ->get();
        foreach ($live as $a) {
            $this->lockObligation($a->obligation_id);
            $this->insertAllocation("{$key}:dealloc:{$a->id}", [
                'movement_id' => $reversal->id,
                'obligation_id' => $a->obligation_id,
                'amount_kopecks' => -$a->amount_kopecks,
                'reverses_allocation_id' => $a->id,
                'reason' => 'D9: сторно снимает распределение',
                'created_by' => $by,
            ]);
        }

        return $reversal;
    }

    /**
     * Перенос снятых сторно распределений на корректировку (идемпотентен по ключам).
     * Приток переносит деньги на обязательства (не больше, чем держит семья);
     * отток (возврат) заново уменьшает те же обязательства, что уменьшал исправляемый возврат.
     */
    private function carryAllocations(MoneyMovement $reversal, MoneyMovement $correction, string $key, ?int $by): void
    {
        // Нетто по обязательству: пара зачёта депозита (−x/+x) переносится как итог, а не по строкам.
        $held = DB::table('money_allocations as x')
            ->join('money_allocations as t', 't.id', '=', 'x.reverses_allocation_id')
            ->join('money_obligations as o', 'o.id', '=', 't.obligation_id')
            ->where('x.movement_id', $reversal->id)
            ->groupBy('t.obligation_id', 'o.delivered_at')
            ->orderByRaw('CASE WHEN o.delivered_at IS NULL THEN 1 ELSE 0 END')
            ->orderByRaw('MIN(t.id)')
            ->selectRaw('t.obligation_id, SUM(t.amount_kopecks) AS held')
            ->get();

        $inflow = $correction->amount_kopecks > 0;
        // Приток: не больше новой суммы и свободного остатка семьи (возвраты уже вычтены).
        // Отток: не больше новой суммы возврата.
        $left = $inflow
            ? min($correction->amount_kopecks, max(0, $this->projection->familyResidue((int) $correction->cap_anchor_id, forUpdate: true)))
            : -$correction->amount_kopecks;
        foreach ($held as $row) {
            $take = min($inflow ? (int) $row->held : -(int) $row->held, $left);
            if ($take <= 0) {
                continue;
            }
            $this->allocateLocked($correction, $this->lockObligation((int) $row->obligation_id), $take, "{$key}:carry:{$row->obligation_id}", 'D9: корректировка наследует распределения', $by);
            $left -= $take;
        }
    }

    private function allocateLocked(MoneyMovement $m, MoneyObligation $o, int $kopecks, string $key, ?string $reason, ?int $by): MoneyAllocation
    {
        $this->positive($kopecks);
        if ($m->type === MoneyMovement::REVERSAL) {
            throw new LedgerInvariantViolation('ledger: a reversal carries only the mirrors of the row it reverses');
        }
        $amount = $m->amount_kopecks > 0 ? $kopecks : -$kopecks;

        $existing = MoneyAllocation::query()->where('allocation_key', $key)->first();
        if ($existing === null) {
            $movementRoom = abs($m->amount_kopecks) - abs($this->projection->movementAllocated($m->id, forUpdate: true));
            if ($kopecks > $movementRoom) {
                throw new LedgerInvariantViolation("ledger: allocations exceed the movement (room {$movementRoom}, asked {$kopecks})");
            }
            if ($amount > 0) {
                $free = $this->projection->familyResidue($m->cap_anchor_id ?? $m->id, forUpdate: true);
                if ($amount > $free) {
                    throw new LedgerInvariantViolation("ledger: a payment family cannot allocate more than it holds (free {$free}, asked {$amount})");
                }
            }
            $held = $this->projection->obligationAllocated($o->id, forUpdate: true);
            if ($held + $amount < 0 || $held + $amount > $o->price_kopecks) {
                throw new LedgerInvariantViolation("ledger: obligation allocation outside 0..price (held {$held}, change {$amount}, price {$o->price_kopecks})");
            }
        }

        return $this->insertAllocation($key, [
            'movement_id' => $m->id,
            'obligation_id' => $o->id,
            'amount_kopecks' => $amount,
            'reason' => $reason,
            'created_by' => $by,
        ]);
    }

    private function insertAllocation(string $key, array $attrs): MoneyAllocation
    {
        $existing = MoneyAllocation::query()->where('allocation_key', $key)->first();
        if ($existing !== null) {
            foreach (['movement_id', 'obligation_id', 'amount_kopecks', 'reverses_allocation_id'] as $f) {
                if ($existing->{$f} != ($attrs[$f] ?? null)) {
                    throw new LedgerReplayConflict("ledger: allocation {$key} replayed with different {$f}");
                }
            }

            return $existing;
        }

        try {
            return $this->translate(fn () => DB::transaction(fn () => MoneyAllocation::query()->create(['allocation_key' => $key] + $attrs)));
        } catch (UniqueConstraintViolationException $e) {
            if (MoneyAllocation::query()->where('allocation_key', $key)->exists()) {
                return $this->insertAllocation($key, $attrs);
            }
            throw new LedgerInvariantViolation('ledger: allocation already reversed', 0, $e);
        }
    }

    /**
     * @param  (Closure(): void)|null  $precheck  выполняется только для НОВОГО факта (не для повтора)
     */
    private function insertMovement(string $key, array $attrs, array $opts = [], ?Closure $precheck = null): MoneyMovement
    {
        foreach (array_diff(array_keys($opts), self::OPTS) as $unknown) {
            throw new LedgerInvariantViolation("ledger: unknown movement option {$unknown}");
        }
        $attrs = array_merge(array_intersect_key($opts, array_flip(self::OPTS)), $attrs);

        $existing = MoneyMovement::query()->where('movement_key', $key)->first();
        if ($existing !== null) {
            return $this->replayed($existing, $attrs);
        }

        $precheck?->__invoke();

        try {
            return $this->translate(fn () => DB::transaction(fn () => MoneyMovement::query()->create(['movement_key' => $key] + $attrs)));
        } catch (UniqueConstraintViolationException $e) {
            $existing = MoneyMovement::query()->where('movement_key', $key)->first();
            if ($existing !== null) {
                return $this->replayed($existing, $attrs);
            }
            throw new LedgerInvariantViolation($this->uniqueMessage($e), 0, $e);
        }
    }

    private function replayed(MoneyMovement $existing, array $attrs): MoneyMovement
    {
        foreach (self::REPLAY_FIELDS as $f) {
            if (! array_key_exists($f, $attrs)) {
                continue;
            }
            if ((string) $existing->getAttribute($f) !== (string) $attrs[$f]) {
                throw new LedgerReplayConflict("ledger: movement {$existing->movement_key} replayed with different {$f}");
            }
        }

        return $existing;
    }

    private function reversalOf(MoneyMovement $m): ?MoneyMovement
    {
        return MoneyMovement::query()->where('reverses_movement_id', $m->id)->first();
    }

    /** Якорь семьи предела возврата для строк цепочки $m (null — строка вне семьи). */
    private function expectedAnchor(MoneyMovement $m): ?int
    {
        $root = $m->isRoot() ? $m : MoneyMovement::query()->findOrFail($m->root_movement_id);

        return match (true) {
            in_array($root->type, MoneyMovement::INFLOW_TYPES, true) => $root->id,
            $root->type === MoneyMovement::REFUND => $root->refund_of_movement_id,
            default => null,
        };
    }

    /** Блокирует якорь семьи (или корень цепочки) — сериализует возвраты/сторно одной оплаты. */
    private function lockFamily(MoneyMovement $m): void
    {
        $this->lockMovement($this->expectedAnchor($m) ?? $m->chainRootId());
    }

    private function lockMovement(int $id): MoneyMovement
    {
        return MoneyMovement::query()->lockForUpdate()->findOrFail($id);
    }

    private function lockObligation(int $id): MoneyObligation
    {
        return MoneyObligation::query()->lockForUpdate()->findOrFail($id);
    }

    private function write(Closure $fn): mixed
    {
        if (! $this->writable()) {
            throw new LedgerWritesDisabled('Денежное ядро (H5443) выключено: features.money_ledger_core=false. В P1 запись идёт только в теневом прогоне.');
        }

        // Гонка на MariaDB 11.8 (innodb_snapshot_isolation=ON, как на проде):
        // проигравший получает 1020 «Record has changed since last read» /
        // deadlock, и ничего не записывается. Верхнеуровневый вызов Laravel
        // повторяет сам — на повторе предел проверяется по свежим данным и
        // даёт обычный отказ «refunds exceed…». Внутри чужой транзакции
        // DeadlockException уходит вызывающему: повторять надо всю его транзакцию.
        return DB::transaction($fn, self::CONCURRENCY_ATTEMPTS);
    }

    /** Ошибка триггера «ledger: …» → LedgerInvariantViolation с тем же текстом. */
    private function translate(Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (UniqueConstraintViolationException $e) {
            throw $e;
        } catch (QueryException $e) {
            if (preg_match('/(ledger: [^(\n]+?)\s*(?:\(Connection|$)/', $e->getMessage(), $m)) {
                throw new LedgerInvariantViolation(trim($m[1]), 0, $e);
            }
            throw $e;
        }
    }

    private function uniqueMessage(UniqueConstraintViolationException $e): string
    {
        $msg = $e->getMessage();

        return match (true) {
            str_contains($msg, 'evidence_key') => 'ledger: evidence already consumed',
            str_contains($msg, 'reverses_movement_id') => 'ledger: movement already reversed',
            str_contains($msg, 'corrects_movement_id') => 'ledger: movement already corrected',
            str_contains($msg, 'pairs_movement_id') => 'ledger: direct teacher receipt already offset',
            default => 'ledger: unique invariant violated',
        };
    }

    private function abs(int $kopecks): int
    {
        return $kopecks < 0 ? -$kopecks : $kopecks;
    }

    private function positive(int $kopecks): void
    {
        if ($kopecks <= 0) {
            throw new LedgerInvariantViolation('ledger: amount must be a positive number of kopecks');
        }
    }
}
