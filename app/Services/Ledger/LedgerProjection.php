<?php

declare(strict_types=1);

namespace App\Services\Ledger;

use App\Models\MoneyAllocation;
use App\Models\MoneyMovement;
use App\Models\MoneyObligation;
use App\Support\Kopecks;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * H5443 (P1): совместимые читатели денежного ядра — только чтение.
 *
 * Ни один экран на них не переключён (переключение — P4 после отчёта
 * «до/после»). Здесь же контрольные суммы и поиск нарушений, на которых P3
 * построит ежедневную сверку. Все суммы — целые копейки; рублёвые строки
 * отдаются через {@see Kopecks::toDecimal()} только для сравнения с легаси.
 */
final class LedgerProjection
{
    /** Чистая сумма цепочки: оригинал + сторно + корректировки. */
    public function chainNet(int $rootId): int
    {
        return (int) MoneyMovement::query()
            ->where(fn ($q) => $q->where('id', $rootId)->orWhere('root_movement_id', $rootId))
            ->sum('amount_kopecks');
    }

    /** Остаток исходной оплаты для возврата: оплата − возвраты (с учётом сторно/корректировок). */
    public function refundableRemaining(MoneyMovement $source, bool $forUpdate = false): int
    {
        $q = DB::table('money_movements')
            ->where(fn ($q) => $q->where('id', $source->id)->orWhere('cap_anchor_id', $source->id));

        return $this->sumKopecks($q, $forUpdate);
    }

    public function movementAllocated(int $movementId, bool $forUpdate = false): int
    {
        return $this->sumKopecks(DB::table('money_allocations')->where('movement_id', $movementId), $forUpdate);
    }

    public function obligationAllocated(int $obligationId, bool $forUpdate = false): int
    {
        return $this->sumKopecks(DB::table('money_allocations')->where('obligation_id', $obligationId), $forUpdate);
    }

    /** Распределено по всей цепочке (оригинал, сторно, корректировки). */
    public function chainAllocated(int $rootId): int
    {
        return (int) DB::table('money_allocations as a')
            ->join('money_movements as m', 'm.id', '=', 'a.movement_id')
            ->where(fn ($q) => $q->where('m.id', $rootId)->orWhere('m.root_movement_id', $rootId))
            ->sum('a.amount_kopecks');
    }

    /** Явный нераспределённый остаток цепочки (D2) — исчезнуть в округлении не может: всё целое. */
    public function unallocatedResidue(int $rootId): int
    {
        return $this->chainNet($rootId) - $this->chainAllocated($rootId);
    }

    /**
     * @return array{price: int, allocated: int, outstanding: int, delivered: bool, cancelled: bool, recognized: int}
     */
    public function obligationState(MoneyObligation $o): array
    {
        $allocated = $this->obligationAllocated($o->id);
        $delivered = $o->delivered_at !== null;

        return [
            'price' => $o->price_kopecks,
            'allocated' => $allocated,
            // Для депозита «outstanding» — ещё не внесённая часть; удерживаемое — allocated.
            'outstanding' => $o->cancelled_at !== null ? 0 : $o->price_kopecks - $allocated,
            'delivered' => $delivered,
            'cancelled' => $o->cancelled_at !== null,
            // D6: пробное и блок признаются только по факту; депозит — никогда.
            'recognized' => $o->isLessonObligation() && $delivered ? $allocated : 0,
        ];
    }

    /**
     * Сводка студента по курсу в форме, сравнимой с легаси-экранами
     * («оплачено», «возвращено», «долг»). $courseId = null — по всем курсам.
     *
     * @return array<string, int|string>
     */
    public function studentCourseSummary(int $userId, ?int $courseId = null): array
    {
        $byRoot = DB::table('money_movements as m')
            ->join('money_movements as r', 'r.id', '=', DB::raw('COALESCE(m.root_movement_id, m.id)'))
            ->where('r.user_id', $userId)
            ->when($courseId !== null, fn ($q) => $q->where('r.course_id', $courseId))
            ->groupBy('r.type')
            ->selectRaw('r.type AS root_type, SUM(m.amount_kopecks) AS net')
            ->pluck('net', 'root_type')
            ->map(fn ($v) => (int) $v);

        $received = (int) ($byRoot[MoneyMovement::RECEIPT] ?? 0) + (int) ($byRoot[MoneyMovement::DIRECT_TEACHER_RECEIPT] ?? 0);
        $refunded = (int) ($byRoot[MoneyMovement::REFUND] ?? 0);
        $compensated = (int) ($byRoot[MoneyMovement::COMPENSATION] ?? 0);

        $studentAllocated = (int) DB::table('money_allocations as a')
            ->join('money_movements as m', 'm.id', '=', 'a.movement_id')
            ->join('money_movements as r', 'r.id', '=', DB::raw('COALESCE(m.root_movement_id, m.id)'))
            ->where('r.user_id', $userId)
            ->when($courseId !== null, fn ($q) => $q->where('r.course_id', $courseId))
            ->sum('a.amount_kopecks');

        $debt = 0;
        $depositHeld = 0;
        $recognized = 0;
        MoneyObligation::query()
            ->where('user_id', $userId)
            ->when($courseId !== null, fn ($q) => $q->where('course_id', $courseId))
            ->orderBy('id')
            ->each(function (MoneyObligation $o) use (&$debt, &$depositHeld, &$recognized): void {
                $s = $this->obligationState($o);
                if ($o->kind === MoneyObligation::DEPOSIT) {
                    $depositHeld += $s['allocated'];

                    return;
                }
                $debt += $s['outstanding'];
                $recognized += $s['recognized'];
            });

        $netPaid = $received + $refunded;

        return [
            'received_kopecks' => $received,
            'refunded_kopecks' => $refunded,
            'compensated_kopecks' => $compensated,
            'net_paid_kopecks' => $netPaid,
            'deposit_held_kopecks' => $depositHeld,
            'recognized_kopecks' => $recognized,
            'debt_outstanding_kopecks' => $debt,
            'unallocated_residue_kopecks' => $netPaid - $studentAllocated,
            'net_paid' => Kopecks::toDecimal($netPaid),
            'debt_outstanding' => Kopecks::toDecimal($debt),
        ];
    }

    /**
     * Контрольные суммы среза: деньги студентов = распределено + явный остаток.
     *
     * @return array<string, int|bool>
     */
    public function controlTotals(): array
    {
        $studentNet = (int) DB::table('money_movements as m')
            ->join('money_movements as r', 'r.id', '=', DB::raw('COALESCE(m.root_movement_id, m.id)'))
            ->whereIn('r.type', [...MoneyMovement::INFLOW_TYPES, MoneyMovement::REFUND])
            ->sum('m.amount_kopecks');
        $allocated = (int) MoneyAllocation::query()->sum('amount_kopecks');

        $netByRootType = fn (string $type) => (int) DB::table('money_movements as m')
            ->join('money_movements as r', 'r.id', '=', DB::raw('COALESCE(m.root_movement_id, m.id)'))
            ->where('r.type', $type)
            ->sum('m.amount_kopecks');

        return [
            'movements' => MoneyMovement::query()->count(),
            'allocations' => MoneyAllocation::query()->count(),
            'obligations' => MoneyObligation::query()->count(),
            'student_money_net_kopecks' => $studentNet,
            'allocated_kopecks' => $allocated,
            'unallocated_residue_kopecks' => $studentNet - $allocated,
            'payouts_net_kopecks' => $netByRootType(MoneyMovement::PAYOUT),
            'compensations_net_kopecks' => $netByRootType(MoneyMovement::COMPENSATION),
            'obligation_price_kopecks' => (int) MoneyObligation::query()->whereNull('cancelled_at')->sum('price_kopecks'),
            'balanced' => $this->integrityBreaches() === [],
        ];
    }

    /**
     * Нарушения инвариантов, которые триггер не может увидеть в одной строке
     * (или которые появились бы при обходе триггеров). Пусто = ядро цело.
     *
     * @return array<string, list<int>>
     */
    public function integrityBreaches(): array
    {
        $ids = fn ($q) => $q->pluck('id')->map(fn ($v) => (int) $v)->all();

        $breaches = [
            // Возвраты семьи превысили исходную оплату (D12).
            'refund_family_negative' => $ids(DB::table('money_movements as s')
                ->whereNull('s.root_movement_id')
                ->whereIn('s.type', MoneyMovement::INFLOW_TYPES)
                ->whereRaw('(SELECT SUM(f.amount_kopecks) FROM money_movements f WHERE f.id = s.id OR f.cap_anchor_id = s.id) < 0')
                ->select('s.id')),
            // Корректировка притока без сторно исправленной строки (двойной учёт, D9).
            'correction_without_reversal' => $ids(DB::table('money_movements as c')
                ->where('c.type', MoneyMovement::CORRECTION)
                ->whereNotExists(fn (Builder $q) => $q->from('money_movements as r')->whereColumn('r.reverses_movement_id', 'c.corrects_movement_id'))
                ->select('c.id')),
            // Распределено больше, чем движение, или с обратным знаком (D2).
            'movement_over_allocated' => $ids(DB::table('money_movements as m')
                ->whereRaw('(CASE WHEN m.amount_kopecks > 0 THEN 1 ELSE -1 END) * COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a WHERE a.movement_id = m.id), 0) NOT BETWEEN 0 AND ABS(m.amount_kopecks)')
                ->select('m.id')),
            // Обязательство распределено за пределы 0..цена.
            'obligation_out_of_range' => $ids(DB::table('money_obligations as o')
                ->whereRaw('COALESCE((SELECT SUM(a.amount_kopecks) FROM money_allocations a WHERE a.obligation_id = o.id), 0) NOT BETWEEN 0 AND o.price_kopecks')
                ->select('o.id')),
            // Прямой платёж преподавателю без погашения обязательства (D16).
            'direct_receipt_without_offset' => $ids(DB::table('money_movements as d')
                ->where('d.type', MoneyMovement::DIRECT_TEACHER_RECEIPT)
                ->whereNotExists(fn (Builder $q) => $q->from('money_movements as p')->whereColumn('p.pairs_movement_id', 'd.id'))
                ->select('d.id')),
            // Сторнированная строка сохранила живые распределения.
            'reversed_with_live_allocations' => $ids(DB::table('money_movements as m')
                ->whereExists(fn (Builder $q) => $q->from('money_movements as r')->whereColumn('r.reverses_movement_id', 'm.id'))
                ->whereExists(fn (Builder $q) => $q->from('money_allocations as a')->whereColumn('a.movement_id', 'm.id')
                    ->whereNull('a.reverses_allocation_id')
                    ->whereNotExists(fn (Builder $q2) => $q2->from('money_allocations as x')->whereColumn('x.reverses_allocation_id', 'a.id')))
                ->select('m.id')),
        ];

        return array_filter($breaches);
    }

    private function sumKopecks(Builder $q, bool $forUpdate): int
    {
        if ($forUpdate) {
            // Блокирующее чтение: видит последние зафиксированные строки даже
            // внутри старого снимка REPEATABLE READ (MariaDB); SQLite игнорирует.
            return (int) $q->lockForUpdate()->get(['amount_kopecks'])->sum('amount_kopecks');
        }

        return (int) $q->sum('amount_kopecks');
    }
}
