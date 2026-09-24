<?php

declare(strict_types=1);

namespace App\Services\Reconciliation;

use App\Models\MoneyAllocation;
use App\Models\MoneyMovement;
use App\Models\MoneyObligation;
use App\Models\Payment;
use App\Services\BlockAccessMaterializer;
use App\Services\Ledger\LedgerInvariantViolation;
use App\Services\Ledger\LedgerProjection;
use App\Services\Ledger\LedgerService;
use App\Support\Kopecks;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * H5445 (P3, D10): «полный возврат отзывает оставшийся доступ; частичный
 * возврат требует явного распределения по блокам».
 *
 * Два контура, одно правило:
 *
 * 1. Легаси (`payments`, живой прод) — за флагом `money_refund_access_rules`
 *    (дефолт OFF). Возврат — строка `Расход` с `refund_of_payment_id`.
 *    Частичный возврат без `start_block..end_block` (или вне диапазона
 *    исходной оплаты) отвергается при сохранении. Полный возврат снимает
 *    access-only siblings исходной оплаты и перестаёт считать её дающей
 *    доступ; частичный — снимает только названные блоки.
 * 2. Ядро P1 (`money_*`, пока тёмное) — {@see refundLedger()}: полный возврат
 *    (всё, что не съедено проведёнными блоками) сам называет неоказанные
 *    обязательства семьи и отменяет те, что остались без денег; частичный при любом живом распределении семьи
 *    обязан назвать обязательства (строже P1, который пускает остаток без
 *    названий: D10 требует блоков, когда деньги уже стоят на блоках).
 *
 * Уже оказанное не стирается: проведённые (delivered) обязательства не
 * отменяются, история строк не редактируется.
 */
final class RefundAccessPolicy
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly LedgerProjection $projection,
    ) {}

    public static function enabled(): bool
    {
        return (bool) config('features.money_refund_access_rules');
    }

    // ---------------------------------------------------------------
    // Легаси-контур
    // ---------------------------------------------------------------

    /** Вызывается из Payment::saving: отвергает частичный возврат без блоков. */
    public function guardLegacyRefund(Payment $refund): void
    {
        if (! self::enabled() || ! $this->isLiveLegacyRefund($refund)) {
            return;
        }

        $original = Payment::query()->find($refund->refund_of_payment_id);
        if ($original === null) {
            throw new RefundAccessViolation('D10: возврат ссылается на несуществующую оплату.');
        }

        if ($this->legacyIsFull($original, $refund)) {
            return;
        }

        if ($refund->start_block === null || $refund->end_block === null) {
            throw new RefundAccessViolation(
                'D10: частичный возврат проводится только с явным распределением по блокам — укажите «с блока» и «по блок», которые он уменьшает.'
            );
        }
        if ((int) $refund->start_block > (int) $refund->end_block) {
            throw new RefundAccessViolation('D10: диапазон блоков возврата перевёрнут.');
        }

        [$from, $to] = self::blockRange($original);
        if ($from !== null && ((int) $refund->start_block < $from || (int) $refund->end_block > $to)) {
            throw new RefundAccessViolation("D10: блоки возврата {$refund->start_block}–{$refund->end_block} вне оплаченных блоков {$from}–{$to}.");
        }
    }

    /** Вызывается из Payment::saved: отзывает неоказанный доступ по возврату. */
    public function applyLegacyRefund(Payment $refund): void
    {
        if (! self::enabled() || ! $this->isLiveLegacyRefund($refund)) {
            return;
        }
        $original = Payment::query()->find($refund->refund_of_payment_id);
        if ($original === null) {
            return;
        }

        $materializer = app(BlockAccessMaterializer::class);
        if ($this->legacyIsFull($original, null)) {
            $materializer->removeSiblingsOf($original);
        } else {
            $blocks = array_map(fn (int $n) => 'block_'.$n, range((int) $refund->start_block, (int) $refund->end_block));
            Payment::withoutEvents(fn () => Payment::query()
                ->where('transaction_id', BlockAccessMaterializer::GRANT_PREFIX.$original->id)
                ->whereIn('tariff', $blocks)
                ->delete());
        }

        // Группы снимаются, только если у студента не осталось ни одной
        // дающей доступ оплаты курса (отозванные этим правилом уже не в счёт).
        $original->reconcileAccessAfterReversal();
    }

    /**
     * Ограничение «оплата ещё даёт доступ»: исключает полностью возвращённые
     * оплаты и собственный блок оплаты, попавший в диапазон её частичного
     * возврата. Применяется в Payment::scopeWithAccessExpiry и в проверке
     * оставшегося доступа к группам — только при включённом флаге.
     */
    public static function excludeRefundedAccess(Builder $query): Builder
    {
        if (! self::enabled()) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $paid = "'".implode("','", Payment::PAID_STATUSES)."'";
        $blockNo = DB::connection()->getDriverName() === 'sqlite'
            ? "CAST(SUBSTR({$table}.tariff, 7) AS INTEGER)"
            : "CAST(SUBSTRING({$table}.tariff, 7) AS UNSIGNED)";

        return $query
            ->whereRaw("NOT ({$table}.amount > 0 AND {$table}.amount + COALESCE((SELECT SUM(r.amount) FROM payments r WHERE r.refund_of_payment_id = {$table}.id AND r.status IN ({$paid})), 0) <= 0)")
            ->whereRaw("NOT (SUBSTR({$table}.tariff, 1, 6) = 'block_' AND EXISTS (SELECT 1 FROM payments r WHERE r.refund_of_payment_id = {$table}.id AND r.status IN ({$paid}) AND r.start_block IS NOT NULL AND r.end_block IS NOT NULL AND {$blockNo} BETWEEN r.start_block AND r.end_block))");
    }

    /**
     * Полный ли возврат: сумма оплаченных возвратов (с учётом сохраняемой
     * строки, если она ещё не в БД или меняет сумму) ≥ исходной суммы.
     */
    public function legacyIsFull(Payment $original, ?Payment $pending): bool
    {
        $originalKopecks = Kopecks::fromDecimal((string) $original->amount);
        $refunded = 0;
        Payment::query()
            ->where('refund_of_payment_id', $original->id)
            ->paid()
            ->when($pending?->exists, fn ($q) => $q->whereKeyNot($pending->id))
            ->pluck('amount')
            ->each(function ($a) use (&$refunded): void {
                $refunded += abs(Kopecks::fromDecimal((string) $a));
            });
        if ($pending !== null) {
            $refunded += abs(Kopecks::fromDecimal((string) $pending->amount));
        }

        return $originalKopecks > 0 && $refunded >= $originalKopecks;
    }

    /** @return array{0: ?int, 1: ?int} оплаченный диапазон блоков исходной оплаты */
    public static function blockRange(Payment $original): array
    {
        if ($original->start_block !== null && $original->end_block !== null) {
            return [(int) $original->start_block, (int) $original->end_block];
        }
        if (is_string($original->tariff) && preg_match('/^block_(\d+)$/', $original->tariff, $m) === 1) {
            return [(int) $m[1], (int) $m[1]];
        }

        return [null, null];
    }

    private function isLiveLegacyRefund(Payment $p): bool
    {
        return $p->refund_of_payment_id !== null
            && in_array($p->status, Payment::PAID_STATUSES, true);
    }

    // ---------------------------------------------------------------
    // Ядро P1
    // ---------------------------------------------------------------

    /**
     * D10 поверх LedgerService::refund.
     *
     * @param  array<int, int>  $fromObligations  obligation_id => копейки, которые возврат снимает
     */
    public function refundLedger(MoneyMovement $source, string $key, int $kopecks, CarbonInterface $occurredAt, array $fromObligations = [], array $opts = []): MoneyMovement
    {
        return DB::transaction(function () use ($source, $key, $kopecks, $occurredAt, $fromObligations, $opts): MoneyMovement {
            $remaining = $this->projection->refundableRemaining($source, forUpdate: true);
            // Проведённое уменьшается только сторно (P1), поэтому «полный»
            // возврат = всё, что не съедено проведёнными блоками.
            $open = [];
            $deliveredHeld = 0;
            foreach ($this->familyHoldings($source->id) as $obligationId => $net) {
                $o = MoneyObligation::query()->findOrFail($obligationId);
                if ($o->delivered_at !== null) {
                    $deliveredHeld += $net;
                } elseif ($o->cancelled_at === null) {
                    $open[$obligationId] = $net;
                }
            }
            $full = $kopecks === $remaining - $deliveredHeld;

            if ($full && $fromObligations === []) {
                $fromObligations = $open;
            }
            if (! $full && $fromObligations === [] && $open !== []) {
                throw new LedgerInvariantViolation('ledger: D10 — a partial refund must name the blocks/obligations it reduces');
            }

            $refund = $this->ledger->refund($source, $key, $kopecks, $occurredAt, $fromObligations, $opts);

            if ($full) {
                foreach (array_keys($open) as $obligationId) {
                    $o = MoneyObligation::query()->findOrFail($obligationId);
                    if ($this->projection->obligationAllocated($o->id) === 0) {
                        $this->ledger->cancel($o, $occurredAt);
                    }
                }
            }

            return $refund;
        });
    }

    /** @return array<int, int> obligation_id => нетто денег семьи на обязательстве (> 0) */
    private function familyHoldings(int $anchorId): array
    {
        return MoneyAllocation::query()
            ->join('money_movements as f', 'f.id', '=', 'money_allocations.movement_id')
            ->where(fn ($q) => $q->where('f.id', $anchorId)->orWhere('f.cap_anchor_id', $anchorId))
            ->groupBy('money_allocations.obligation_id')
            ->havingRaw('SUM(money_allocations.amount_kopecks) > 0')
            ->orderBy('money_allocations.obligation_id')
            ->selectRaw('money_allocations.obligation_id AS ob, SUM(money_allocations.amount_kopecks) AS net')
            ->pluck('net', 'ob')
            ->mapWithKeys(fn ($net, $ob) => [(int) $ob => (int) $net])
            ->all();
    }
}
