<?php

declare(strict_types=1);

namespace App\Services\Promises;

use App\Models\Payment;
use App\Models\PaymentAudit;
use App\Models\PaymentPromise;
use Illuminate\Support\Collection;

/**
 * H7268 — read-only классификатор строк «доступ под обещание»
 * (`transaction_id = promise_grant_#<promise id>`, создаёт
 * {@see \App\Services\ConditionalAccessGranter}).
 *
 * Контракт строки (docs/money-access-core-manual.md §1.6, §640):
 *  - `is_conditional = true` и `amount = 0` — доступ без денег; строка обязана
 *    быть исключена из каждого денежного агрегата через `Payment::real()`;
 *  - `status` — один из {@see Payment::PAID_STATUSES}: именно статус делает
 *    тариф видимым гейту `getUserUnlockedTariffs`, отдельного ACL у доступа нет;
 *  - `linked_promise_id` и `transaction_id` называют ОДНО обещание, а обещание
 *    принадлежит тому же студенту и курсу (иначе доступ одного открывает курс
 *    другому);
 *  - `tariff` — доступ-образный (`full` / `block_N`);
 *  - `first_paid_at` заполнен (H1645: без него тихая строка невидима защите от
 *    повторного применения вебхука).
 *
 * Аудита `created` у такой строки МОЖЕТ не быть: граnter создаёт строку через
 * `Payment::withoutEvents()` (осознанно — иначе на каждый блок улетело бы
 * отдельное TG-сообщение). Это документированный пробел, а не дефект денег:
 * классификатор помечает его отдельным вердиктом и предлагает ТОЛЬКО
 * append-only восстановление снимка (`payments:backfill-promise-grant-audits`).
 *
 * Классификатор ничего не пишет. Ни один вердикт сам по себе не меняет статус,
 * суммы, доступ или аудит.
 */
class PromiseGrantClassifier
{
    public const VERDICT_LEGIT = 'LEGIT_ACCESS_MARKER';

    /** Контракт соблюдён, но следа `created` в payment_audits нет. */
    public const VERDICT_LEGIT_SILENT = 'LEGIT_ACCESS_MARKER_SILENT_AUDIT_GAP';

    /** Строка нарушает контракт, но исправление — не аудит: нужен человек. */
    public const VERDICT_HUMAN = 'HUMAN_REQUIRED';

    /** Нарушение контракта, устранимое только деньгами/доступом — авто-фикса нет. */
    public const VERDICT_DEFECT = 'DEFECT';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_HUMAN = 'human';

    public const SEVERITY_DEFECT = 'defect';

    /** Доступ-образные тарифы, которые умеет выдавать granter. */
    private const ACCESS_TARIFF_PATTERN = '/^(full|lesson|block_\d+)$/';

    /**
     * Кандидаты: всё, что выглядит как строка обещания, даже если флаг
     * `is_conditional` потерян (именно такой дефект и надо поймать).
     *
     * @param  list<int>|null  $ids
     * @return Collection<int, Payment>
     */
    public function candidates(?array $ids = null): Collection
    {
        return Payment::query()
            ->when(! empty($ids), fn ($q) => $q->whereIn('id', $ids))
            ->where(function ($q): void {
                $q->where('is_conditional', true)
                    ->orWhere('transaction_id', 'like', 'promise_grant_#%')
                    ->orWhereNotNull('linked_promise_id');
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{
     *     payment_id: int,
     *     transaction_id: string|null,
     *     linked_promise_id: int|null,
     *     verdict: string,
     *     repair: string,
     *     findings: list<array{code: string, severity: string, message: string}>
     * }
     */
    public function classify(Payment $payment): array
    {
        $findings = [];

        if (! $payment->is_conditional) {
            $findings[] = $this->finding(
                'conditional_flag_missing',
                self::SEVERITY_DEFECT,
                'amount=0/paid строка без is_conditional=true читается денежными агрегатами как настоящий платёж; '
                .'флаг — денежное поле, решение и запись — за человеком.',
            );
        }

        if (! in_array((string) $payment->status, Payment::PAID_STATUSES, true)) {
            $findings[] = $this->finding(
                'status_does_not_grant_access',
                self::SEVERITY_HUMAN,
                sprintf(
                    'status=%s: гейт уроков читает только %s, значит доступ по обещанию фактически закрыт. '
                    .'Смена статуса — изменение доступа, решает человек.',
                    (string) $payment->status,
                    implode('|', Payment::PAID_STATUSES),
                ),
            );
        }

        if ((float) $payment->amount !== 0.0) {
            $findings[] = $this->finding(
                'conditional_row_carries_money',
                self::SEVERITY_DEFECT,
                sprintf('amount=%s у строки доступа: доступ под обещание не приносит денег.', (string) $payment->amount),
            );
        }

        if (Payment::query()->real()->whereKey($payment->getKey())->exists()) {
            $findings[] = $this->finding(
                'revenue_scope_leak',
                self::SEVERITY_DEFECT,
                'строка видна денежному срезу Payment::real() — zero-amount строка обещания не должна попадать в выручку.',
            );
        }

        $promiseId = $this->promiseIdFromTransaction((string) $payment->transaction_id);

        if ($payment->linked_promise_id === null) {
            $findings[] = $this->finding(
                'missing_promise_link',
                self::SEVERITY_DEFECT,
                'linked_promise_id пуст: строку невозможно связать с обещанием, которое она открывает.',
            );
        } elseif ($promiseId !== null && (int) $payment->linked_promise_id !== $promiseId) {
            $findings[] = $this->finding(
                'promise_link_mismatch',
                self::SEVERITY_DEFECT,
                sprintf(
                    'transaction_id называет обещание #%d, а linked_promise_id — #%d.',
                    $promiseId,
                    (int) $payment->linked_promise_id,
                ),
            );
        } elseif ($promiseId === null) {
            $findings[] = $this->finding(
                'transaction_id_not_promise_grant',
                self::SEVERITY_DEFECT,
                sprintf('transaction_id=%s не следует форме promise_grant_#<id>.', (string) $payment->transaction_id),
            );
        }

        if (! preg_match(self::ACCESS_TARIFF_PATTERN, (string) $payment->tariff)) {
            $findings[] = $this->finding(
                'non_access_tariff',
                self::SEVERITY_DEFECT,
                sprintf('tariff=%s не открывает уроки (ожидается full|block_N).', (string) $payment->tariff),
            );
        }

        if ($payment->first_paid_at === null) {
            $findings[] = $this->finding(
                'missing_first_paid_at',
                self::SEVERITY_HUMAN,
                'first_paid_at пуст: H1645-защита от повторного применения вебхука такую строку не видит.',
            );
        }

        $promise = $payment->linked_promise_id !== null
            ? PaymentPromise::query()->find($payment->linked_promise_id)
            : null;

        if ($payment->linked_promise_id !== null && $promise === null) {
            $findings[] = $this->finding(
                'orphan_promise',
                self::SEVERITY_DEFECT,
                sprintf('обещание #%d не существует (нарушен FK/история).', (int) $payment->linked_promise_id),
            );
        } elseif ($promise !== null) {
            if ((int) $promise->user_id !== (int) $payment->user_id) {
                $findings[] = $this->finding(
                    'promise_student_mismatch',
                    self::SEVERITY_DEFECT,
                    sprintf('обещание #%d принадлежит студенту #%d, а платёж — #%d.', $promise->id, $promise->user_id, $payment->user_id),
                );
            }
            if ((int) $promise->course_id !== (int) $payment->course_id) {
                $findings[] = $this->finding(
                    'promise_course_mismatch',
                    self::SEVERITY_DEFECT,
                    sprintf('обещание #%d про курс #%d, а платёж — про курс #%d.', $promise->id, $promise->course_id, $payment->course_id),
                );
            }
        }

        $hasAuditTrail = PaymentAudit::query()->where('payment_id', $payment->getKey())->exists();

        if (! $hasAuditTrail) {
            $findings[] = $this->finding(
                'silent_creation_no_audit',
                self::SEVERITY_INFO,
                'payment_audits пуст: строка создана ConditionalAccessGranter через withoutEvents (документировано). '
                .'Деньги и доступ не двигались; восстановим только append-only снимок.',
            );
        }

        $verdict = $this->verdictFor($findings);

        return [
            'payment_id' => (int) $payment->getKey(),
            'transaction_id' => $payment->transaction_id,
            'linked_promise_id' => $payment->linked_promise_id !== null ? (int) $payment->linked_promise_id : null,
            'verdict' => $verdict,
            'repair' => $this->repairFor($verdict),
            'findings' => $findings,
        ];
    }

    /**
     * @param  list<array{code: string, severity: string, message: string}>  $findings
     */
    private function verdictFor(array $findings): string
    {
        $severities = array_column($findings, 'severity');

        if (in_array(self::SEVERITY_DEFECT, $severities, true)) {
            return self::VERDICT_DEFECT;
        }

        if (in_array(self::SEVERITY_HUMAN, $severities, true)) {
            return self::VERDICT_HUMAN;
        }

        $silent = in_array('silent_creation_no_audit', array_column($findings, 'code'), true);

        return $silent ? self::VERDICT_LEGIT_SILENT : self::VERDICT_LEGIT;
    }

    private function repairFor(string $verdict): string
    {
        return match ($verdict) {
            self::VERDICT_LEGIT_SILENT => 'audit_backfill',
            self::VERDICT_LEGIT => 'none',
            default => 'human',
        };
    }

    /**
     * @return array{code: string, severity: string, message: string}
     */
    private function finding(string $code, string $severity, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => $message];
    }

    private function promiseIdFromTransaction(string $transactionId): ?int
    {
        return preg_match('/^promise_grant_#(\d+)$/', $transactionId, $m) === 1 ? (int) $m[1] : null;
    }
}
