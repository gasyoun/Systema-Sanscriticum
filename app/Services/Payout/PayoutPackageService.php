<?php

declare(strict_types=1);

namespace App\Services\Payout;

use App\Models\TeacherPayoutPackage;
use App\Models\TeacherPayoutPackageLine;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * H5444 (P2, D13/D14/D11/D16): единственная точка записи расчётных пакетов.
 *
 * Конечный автомат `draft → approved → paid → reversed`; `approved`
 * замораживает состав; расчёт ведётся В КОПЕЙКАХ РУБЛЯ, а курс и валютная
 * сумма снимаются последним шагом, уже после авансов и взаимозачётов (D14).
 *
 * Каждое правило продублировано триггером БД (миграция 2026_09_24_190000):
 * сервис добавляет блокировки строк, идемпотентный повтор по стабильному ключу
 * и понятные сообщения. Без флага features.money_payout_packages запись
 * запрещена — P2 не переключает ни одного читателя.
 */
final class PayoutPackageService
{
    private const CONCURRENCY_ATTEMPTS = 3;

    public function __construct(private readonly CompensationResolver $resolver) {}

    public function writable(): bool
    {
        return (bool) config('features.money_payout_packages');
    }

    private function guard(): void
    {
        if (! $this->writable()) {
            throw new PayoutWritesDisabled(
                'payout: package writes are off — enable features.money_payout_packages (MONEY_PAYOUT_PACKAGES=true) first',
            );
        }
    }

    /**
     * Черновик расчётного окна. Повтор с тем же ключом возвращает ТОТ ЖЕ пакет
     * и не создаёт второго (D13) — в том числе при гонке двух нажатий.
     */
    public function draft(int $teacherId, CarbonInterface $periodStart, CarbonInterface $periodEnd, string $scope = 'period'): TeacherPayoutPackage
    {
        $this->guard();

        $key = TeacherPayoutPackage::keyFor($teacherId, $periodStart->toDateString(), $periodEnd->toDateString(), $scope);

        for ($attempt = 1; ; $attempt++) {
            $existing = TeacherPayoutPackage::where('package_key', $key)->first();

            if ($existing !== null) {
                return $existing;
            }

            try {
                return TeacherPayoutPackage::create([
                    'package_key' => $key,
                    'teacher_id' => $teacherId,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodEnd->toDateString(),
                    'state' => TeacherPayoutPackage::STATE_DRAFT,
                ]);
            } catch (UniqueConstraintViolationException $e) {
                // Гонка: пакет создан параллельной сессией между SELECT и INSERT.
                if ($attempt >= self::CONCURRENCY_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Добавить строку в черновик и пересчитать итог. Повтор с тем же
     * `line_key` — тот же факт, а не второе удержание.
     *
     * @param  array<string, mixed>  $attrs
     */
    public function addLine(TeacherPayoutPackage $package, string $lineKey, string $kind, int $amountKopecks, array $attrs = []): TeacherPayoutPackageLine
    {
        $this->guard();

        return DB::transaction(function () use ($package, $lineKey, $kind, $amountKopecks, $attrs) {
            $locked = TeacherPayoutPackage::whereKey($package->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isFrozen()) {
                throw new PayoutTransitionRefused(
                    "payout: package {$locked->package_key} is {$locked->state} — its composition is frozen (D13)",
                );
            }

            $existing = TeacherPayoutPackageLine::where('line_key', $lineKey)->first();

            if ($existing !== null) {
                if ((int) $existing->package_id !== (int) $locked->getKey()
                    || $existing->kind !== $kind
                    || (int) $existing->amount_kopecks !== $amountKopecks) {
                    throw new PayoutReplayConflict(
                        "payout: line key {$lineKey} already records a different fact",
                    );
                }

                return $existing;
            }

            $line = TeacherPayoutPackageLine::create($attrs + [
                'line_key' => $lineKey,
                'package_id' => $locked->getKey(),
                'kind' => $kind,
                'amount_kopecks' => $amountKopecks,
                'created_at' => Carbon::now(),
            ]);

            $this->recompute($locked);

            $package->refresh();

            return $line;
        });
    }

    /**
     * Удержание возврата (D11): ровно один раз, только за НЕОКАЗАННЫЙ блок,
     * со ссылками на исходное движение возврата и обязательство. Повторная
     * попытка по той же паре отвергается уникальным индексом БД.
     */
    public function deductRefund(TeacherPayoutPackage $package, int $refundMovementId, int $obligationId, int $amountKopecks, ?string $description = null): TeacherPayoutPackageLine
    {
        return $this->addLine(
            $package,
            sprintf('%s:refund:%d:%d', $package->package_key, $refundMovementId, $obligationId),
            TeacherPayoutPackageLine::KIND_REFUND_ADJUSTMENT,
            -abs($amountKopecks),
            [
                'refund_movement_id' => $refundMovementId,
                'obligation_id' => $obligationId,
                'description' => $description,
            ],
        );
    }

    /**
     * Погашение прямого получения денег преподавателем (D16): вторая половина
     * пары «платёж студента + погашение обязательства института». Само
     * движение `direct_teacher_receipt` и платёж студента пишет P1-ядро;
     * здесь оно уменьшает выплату, а доказательство потребляется один раз.
     */
    public function offsetDirectReceipt(TeacherPayoutPackage $package, int $directReceiptMovementId, int $amountKopecks, string $evidenceKey, ?string $description = null): TeacherPayoutPackageLine
    {
        return $this->addLine(
            $package,
            sprintf('%s:direct:%d', $package->package_key, $directReceiptMovementId),
            TeacherPayoutPackageLine::KIND_DIRECT_RECEIPT_OFFSET,
            -abs($amountKopecks),
            [
                'direct_receipt_movement_id' => $directReceiptMovementId,
                'evidence_key' => $evidenceKey,
                'description' => $description,
            ],
        );
    }

    /** Утверждение: состав, ставки и применённые блоки замораживаются (D13). */
    public function approve(TeacherPayoutPackage $package, int $approvedBy): TeacherPayoutPackage
    {
        $this->guard();

        return DB::transaction(function () use ($package, $approvedBy) {
            $locked = TeacherPayoutPackage::whereKey($package->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state === TeacherPayoutPackage::STATE_APPROVED) {
                return $locked; // идемпотентно
            }

            $this->assertTransition($locked, TeacherPayoutPackage::STATE_APPROVED);

            if ($locked->lines()->count() === 0) {
                throw new PayoutTransitionRefused("payout: package {$locked->package_key} has no lines to approve");
            }

            $this->recompute($locked);
            $locked->refresh();

            $locked->forceFill([
                'state' => TeacherPayoutPackage::STATE_APPROVED,
                'approved_at' => Carbon::now(),
                'approved_by' => $approvedBy,
            ])->save();

            $package->refresh();

            return $locked;
        });
    }

    /**
     * Оплата (D13/D14). Рубль — источник истины: итог уже посчитан в копейках,
     * здесь он ТОЛЬКО конвертируется — курс, его дата, источник и производная
     * сумма фиксируются снимком и в повторный расчёт базы не участвуют.
     *
     * @param  array{currency: string, rate: string|float, rate_date: string, source: string}|null  $fx
     */
    public function pay(TeacherPayoutPackage $package, int $paidBy, string $paymentEvidenceKey, ?array $fx = null, ?int $payoutMovementId = null): TeacherPayoutPackage
    {
        $this->guard();

        return DB::transaction(function () use ($package, $paidBy, $paymentEvidenceKey, $fx, $payoutMovementId) {
            $locked = TeacherPayoutPackage::whereKey($package->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state === TeacherPayoutPackage::STATE_PAID) {
                if ($locked->payment_evidence_key !== $paymentEvidenceKey) {
                    throw new PayoutReplayConflict(
                        "payout: package {$locked->package_key} is already paid against other evidence",
                    );
                }

                return $locked; // идемпотентно
            }

            $this->assertTransition($locked, TeacherPayoutPackage::STATE_PAID);

            $snapshot = $this->fxSnapshot((int) $locked->total_kopecks, $fx);

            $locked->forceFill($snapshot + [
                'state' => TeacherPayoutPackage::STATE_PAID,
                'paid_at' => Carbon::now(),
                'paid_by' => $paidBy,
                'payment_evidence_key' => $paymentEvidenceKey,
                'payout_movement_id' => $payoutMovementId,
            ])->save();

            $package->refresh();

            return $locked;
        });
    }

    /** Отмена уже проведённого пакета — только вперёд, в `reversed` (D13). */
    public function reverse(TeacherPayoutPackage $package, int $reversedBy, string $reason): TeacherPayoutPackage
    {
        $this->guard();

        return DB::transaction(function () use ($package, $reversedBy, $reason) {
            $locked = TeacherPayoutPackage::whereKey($package->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->state === TeacherPayoutPackage::STATE_REVERSED) {
                return $locked;
            }

            $this->assertTransition($locked, TeacherPayoutPackage::STATE_REVERSED);

            $locked->forceFill([
                'state' => TeacherPayoutPackage::STATE_REVERSED,
                'reversed_at' => Carbon::now(),
                'reversed_by' => $reversedBy,
                'reversal_reason' => $reason,
            ])->save();

            $package->refresh();

            return $locked;
        });
    }

    /**
     * Валютный снимок финального рублёвого итога (D14). Округление — половина
     * вверх по модулю, целочисленно: ни одной операции с плавающей точкой над
     * деньгами. Курс хранится строкой в 8 знаках, умножение идёт в целых.
     *
     * @param  array{currency: string, rate: string|float, rate_date: string, source: string}|null  $fx
     * @return array<string, mixed>
     */
    private function fxSnapshot(int $totalKopecks, ?array $fx): array
    {
        if ($fx === null || strtoupper($fx['currency']) === 'RUB') {
            return [
                'payout_currency' => 'RUB',
                'fx_rate' => null,
                'fx_rate_date' => $fx['rate_date'] ?? null,
                'fx_source' => $fx['source'] ?? null,
                'payout_amount_minor' => $totalKopecks,
            ];
        }

        // Курс — рублей за 1 единицу валюты, 8 знаков после точки.
        $scaled = (int) round((float) $fx['rate'] * 100_000_000);

        if ($scaled <= 0) {
            throw new PayoutTransitionRefused('payout: an exchange rate must be positive');
        }

        $minor = intdiv($totalKopecks * 100_000_000 + intdiv($scaled, 2), $scaled);

        return [
            'payout_currency' => strtoupper($fx['currency']),
            'fx_rate' => number_format((float) $fx['rate'], 8, '.', ''),
            'fx_rate_date' => $fx['rate_date'],
            'fx_source' => $fx['source'],
            'payout_amount_minor' => $minor,
        ];
    }

    /** Пересчитать колонки итога из строк. Только для черновика. */
    private function recompute(TeacherPayoutPackage $package): void
    {
        $sums = [
            'base_kopecks' => 0,
            'advance_kopecks' => 0,
            'offset_kopecks' => 0,
            'refund_adjustment_kopecks' => 0,
        ];

        foreach ($package->lines()->get() as $line) {
            $column = TeacherPayoutPackageLine::DEDUCTION_COLUMNS[$line->kind] ?? 'base_kopecks';
            $sums[$column] += (int) $line->amount_kopecks;
        }

        $sums['total_kopecks'] = array_sum($sums);

        if ($sums['total_kopecks'] < 0) {
            throw new PayoutTransitionRefused(sprintf(
                'payout: package %s totals %d kopecks — a negative ruble total is booked as a separate correction, never as a payout (D13)',
                $package->package_key,
                $sums['total_kopecks'],
            ));
        }

        $package->forceFill($sums)->save();
    }

    private function assertTransition(TeacherPayoutPackage $package, string $to): void
    {
        $allowed = [
            TeacherPayoutPackage::STATE_DRAFT => [TeacherPayoutPackage::STATE_APPROVED],
            TeacherPayoutPackage::STATE_APPROVED => [TeacherPayoutPackage::STATE_PAID, TeacherPayoutPackage::STATE_REVERSED],
            TeacherPayoutPackage::STATE_PAID => [TeacherPayoutPackage::STATE_REVERSED],
            TeacherPayoutPackage::STATE_REVERSED => [],
        ];

        if (! in_array($to, $allowed[$package->state] ?? [], true)) {
            throw new PayoutTransitionRefused(
                "payout: {$package->state} → {$to} is not a legal package transition (D13)",
            );
        }
    }
}
