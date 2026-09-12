<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Payment;
use App\Models\Teacher;
use App\Models\TeacherPayout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * H4597 — backfill ИСТОРИЧЕСКИХ выплат в teacher_payouts по манифесту.
 *
 * Манифест (Uprava data/h4597_backfill_manifest_*.json, приватный Uprava —
 * в публичный Systema не коммитится) несёт построчные доли платежей
 * {course_id, block_number, payment_id, share, receipt}, реконструированные
 * из листов Марии + Xoom-выписки с чек-суммами. Команда:
 *  - dry-run по умолчанию (образец PostTeacherPayouts);
 *  - ПЕРЕПРОВЕРЯЕТ каждую долю против ЖИВОЙ базы (fail-closed): платёж
 *    существует, paid, real, school, сумма/курс совпадают, приход ≤ cutoff;
 *  - идемпотентно: строка с тем же teacher+paid_at+маркером «H4597 backfill:»
 *    пропускается, повторный прогон ничего не дублирует;
 *  - пишет ТОЛЬКО teacher_payouts (breakdown.prior_blocks_paid — форма,
 *    которую читает paidShareKeys); Финансы-зеркало НЕ создаёт (это
 *    salary:post-payouts после ревью), money-таблицы не трогает;
 *  - прод-запись (--apply) — только после независимого PASS верификатора
 *    (class money, H4358) в разделе ## Verifier хендоффа.
 */
final class BackfillHistoryService
{
    public const COMMENT_MARKER = 'H4597 backfill:';

    /** Порог ⚠ для чек-сумм (не блокирует — строка помечается для верификатора). */
    public const FLAG_DELTA_PCT = 5.0;

    /**
     * Полная проверка манифеста против живой базы. Возвращает план строк.
     * Бросает RuntimeException при ЛЮБОМ расхождении (fail-closed).
     *
     * @param  array<string, mixed>  $manifest
     * @return array{rows: list<array<string, mixed>>, skipped: list<array<string, mixed>>, flagged: list<string>, totals: array{inserts: int, shares: int, rub: float}}
     */
    public function plan(array $manifest, ?int $teacherFilter = null): array
    {
        if ((bool) ($manifest['dump_fingerprint']['moved'] ?? false)) {
            throw new RuntimeException('манифест помечен moved=true — дамп не read-only, отказ');
        }

        $rows = [];
        $skipped = [];
        $flagged = [];
        $inserts = 0;
        $sharesTotal = 0;
        $rubTotal = 0.0;

        foreach ($manifest['rows'] as $row) {
            if (($row['status'] ?? null) !== 'keyed') {
                continue; // parked/Xoom-only строки ключей не несут
            }
            $teacherId = (int) $row['teacher_id'];
            if ($teacherFilter !== null && $teacherId !== $teacherFilter) {
                continue;
            }

            $teacher = Teacher::query()->find($teacherId);
            if ($teacher === null) {
                throw new RuntimeException("преподаватель #{$teacherId} не найден (строка {$row['inventory_id']})");
            }

            // Идемпотентность: уже есть такая backfill-строка?
            $existing = TeacherPayout::query()
                ->where('teacher_id', $teacherId)
                ->whereDate('paid_at', Carbon::parse((string) $row['paid_at'])->toDateString())
                ->where('comment', 'like', self::COMMENT_MARKER.'%')
                ->get();
            $dup = $existing->first(fn (TeacherPayout $p): bool => abs((float) $p->amount - (float) $row['amount']) < 0.01);
            if ($dup !== null) {
                $skipped[] = ['inventory_id' => $row['inventory_id'], 'reason' => "уже есть payout #{$dup->id}"];

                continue;
            }
            if ($existing->isNotEmpty()) {
                throw new RuntimeException(
                    "строка {$row['inventory_id']}: на {$row['paid_at']} уже есть backfill-строка с другой суммой — ручной разбор");
            }

            // Перепроверка каждой доли против живой базы.
            $priorBlocksPaid = [];
            foreach ($row['shares'] as $share) {
                $priorBlocksPaid[] = $this->verifyShare($row, $share);
            }

            $delta = (float) ($row['checksum_delta_pct'] ?? 0.0);
            if ($delta > self::FLAG_DELTA_PCT) {
                $flagged[] = sprintf(
                    'строка %s: Δ чек-суммы %.2f%% (base %.2f → %.2f vs ведомость %.2f) — верификатор решает',
                    $row['inventory_id'], $delta, (float) $row['base_rub'],
                    (float) $row['checksum_school_rub'], (float) $row['expected_school_rub']);
            }

            $rows[] = [
                'inventory_id' => (string) $row['inventory_id'],
                'teacher_id' => $teacherId,
                'teacher_name' => (string) $teacher->name,
                'paid_at' => Carbon::parse((string) $row['paid_at'])->toDateString(),
                'period_month' => (string) ($row['period_month'] ?? substr((string) $row['paid_at'], 0, 7)),
                'amount' => (float) $row['amount'],
                'amount_foreign' => $row['amount_foreign'] !== null ? (float) $row['amount_foreign'] : null,
                'payout_currency' => $row['payout_currency'] ?? null,
                'exchange_rate' => ($row['amount_foreign'] ?? null) && (float) $row['amount_foreign'] > 0
                    ? round((float) $row['amount'] / (float) $row['amount_foreign'], 4)
                    : null,
                'rate_date' => Carbon::parse((string) $row['paid_at'])->toDateString(),
                'comment' => self::COMMENT_MARKER.' '.(string) $row['comment'],
                'breakdown' => [
                    'prior_blocks_paid' => $priorBlocksPaid,
                    'source_quote' => (string) $row['source_quote'],
                    'checksum' => [
                        'base_rub' => (float) $row['base_rub'],
                        'school_rub' => (float) ($row['checksum_school_rub'] ?? 0),
                        'sheet_rub' => (float) ($row['expected_school_rub'] ?? 0),
                        'delta_pct' => $delta,
                    ],
                    'note' => 'H4597: доли уже выплаченных платежей (paidShareKeys), восстановлено из листов Марии/Xoom; amount = фактически выплаченная ведомость',
                ],
                'shares_n' => count($priorBlocksPaid),
            ];

            $inserts++;
            $sharesTotal += count($priorBlocksPaid);
            $rubTotal += (float) $row['amount'];
        }

        return [
            'rows' => $rows,
            'skipped' => $skipped,
            'flagged' => $flagged,
            'totals' => ['inserts' => $inserts, 'shares' => $sharesTotal, 'rub' => round($rubTotal, 2)],
        ];
    }

    /** Применяет план (только teacher_payouts, одна транзакция). */
    public function apply(array $plan): int
    {
        $created = 0;
        DB::transaction(function () use ($plan, &$created): void {
            foreach ($plan['rows'] as $row) {
                TeacherPayout::query()->create([
                    'teacher_id' => $row['teacher_id'],
                    'amount' => $row['amount'],
                    'type' => TeacherPayout::TYPE_REGULAR,
                    'paid_at' => $row['paid_at'],
                    'period_month' => $row['period_month'],
                    'salary_type' => 'percent',
                    'payout_currency' => $row['payout_currency'],
                    'exchange_rate' => $row['exchange_rate'],
                    'rate_date' => $row['rate_date'],
                    'amount_foreign' => $row['amount_foreign'],
                    'comment' => $row['comment'],
                    'breakdown' => $row['breakdown'],
                ]);
                $created++;
            }
        });

        return $created;
    }

    /**
     * Перепроверка одной доли. Возвращает форму для breakdown.prior_blocks_paid.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $share
     * @return array<string, mixed>
     */
    private function verifyShare(array $row, array $share): array
    {
        $paymentId = (int) $share['payment_id'];
        $courseId = (int) $share['course_id'];
        $blockNumber = (int) $share['block_number'];

        $p = Payment::query()->find($paymentId);
        if ($p === null) {
            throw new RuntimeException("строка {$row['inventory_id']}: платёж #{$paymentId} исчез из базы");
        }
        if (! in_array((string) $p->status, Payment::PAID_STATUSES, true) || $p->is_conditional) {
            throw new RuntimeException("строка {$row['inventory_id']}: платёж #{$paymentId} больше не paid/real");
        }
        if ((string) $p->received_account !== Payment::RECEIVED_SCHOOL) {
            throw new RuntimeException("строка {$row['inventory_id']}: платёж #{$paymentId} сменил received_account");
        }
        if ((float) $p->amount != (float) $share['amount']) {
            throw new RuntimeException(sprintf(
                'строка %s: платёж #%d сумма изменилась %.2f ≠ %.2f',
                $row['inventory_id'], $paymentId, (float) $p->amount, (float) $share['amount']));
        }
        if ((int) $p->course_id !== (int) $courseId) {
            throw new RuntimeException("строка {$row['inventory_id']}: платёж #{$paymentId} сменил курс");
        }

        $receipt = Carbon::parse((string) ($share['receipt'] ?? $share['date']));
        $cutoff = Carbon::parse((string) $row['cutoff']);
        if ($receipt->gt($cutoff)) {
            throw new RuntimeException(sprintf(
                'строка %s: платёж #%d приход %s позже cutoff %s',
                $row['inventory_id'], $paymentId, $receipt->toDateString(), $cutoff->toDateString()));
        }

        return [
            'course_id' => (int) $courseId,
            'block_number' => $blockNumber,
            'payment_id' => $paymentId,
            'share' => (float) $share['share'],
            'receipt' => $receipt->toDateString(),
            'user_id' => (int) ($share['user_id'] ?? $p->user_id),
        ];
    }
}
