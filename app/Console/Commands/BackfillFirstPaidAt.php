<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Бэкофилл payments.first_paid_at (H1645, закрывает слепую зону
 * резурекшн-гварда — docs/money-access-core-manual.md §9 C3).
 *
 * Источник правды на платёж — САМЫЙ РАННИЙ аудит-переход, доказывающий
 * «был оплачен» (payment_audits, есть только с 08-06-2026): `created`-снимок
 * сразу оплаченным (скаляр), `updated`-диф pending→paid (новое значение
 * paid), И `updated`-диф paid→failed (старое значение paid — H1645 Step 0
 * hardening, см. Payment::hasPriorPaidTransition; ловит и платёж, оплаченный
 * ДО появления обсёрвера, но отменённый уже после). Если такого следа нет,
 * но платёж СЕЙЧАС оплачен — берём created_at как
 * самый правдоподобный из доступных моментов (до-аудитный платёж, который
 * никогда не реверсился, иначе аудит на реверс уже был бы). Если следа нет
 * И платёж сейчас НЕ оплачен — до-аудитный реверс без следа: правдоподобного
 * момента не существует, first_paid_at остаётся NULL, счётчик остатка
 * печатается в отчёте (не количество строк, а именно count — H1645
 * оговаривает документировать остаток, не список).
 *
 * Идемпотентна: обрабатывает только first_paid_at IS NULL, повторный прогон
 * над уже забэкфилленными платежами no-op. --apply пишет через updateQuietly
 * (никаких обсерверов/событий — сама операция не финансовое событие, просто
 * простановка исторического штампа); без --apply — только отчёт, БД не трогаем.
 */
class BackfillFirstPaidAt extends Command
{
    protected $signature = 'payments:backfill-first-paid-at {--apply : Записать изменения (без флага — только отчёт, БД не трогаем)}';

    protected $description = 'Backfill payments.first_paid_at из текущего статуса + истории payment_audits (H1645).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $total = 0;
        $stamped = 0;
        $noEvidence = 0;

        Payment::query()
            ->whereNull('first_paid_at')
            ->orderBy('id')
            ->chunkById(500, function ($chunk) use ($apply, &$total, &$stamped, &$noEvidence): void {
                foreach ($chunk as $payment) {
                    $total++;

                    $stampAt = $this->earliestPaidMoment($payment);

                    if ($stampAt === null) {
                        $noEvidence++;

                        continue;
                    }

                    $stamped++;

                    if ($apply) {
                        $payment->updateQuietly(['first_paid_at' => $stampAt]);
                    }
                }
            });

        $this->info($apply
            ? 'Применено.'
            : 'Сухой прогон (запись НЕ выполнялась) — добавьте --apply, чтобы записать:');
        $this->line("  кандидатов (first_paid_at IS NULL): {$total}");
        $this->line("  будет проставлено: {$stamped}");
        $this->line("  без доказательства оплаты, остаются NULL (до-аудитный реверс без следа): {$noEvidence}");

        return self::SUCCESS;
    }

    private function earliestPaidMoment(Payment $payment): ?Carbon
    {
        $fromAudit = $this->earliestAuditEnteringPaid($payment);
        if ($fromAudit !== null) {
            return $fromAudit;
        }

        // Нет аудит-следа вообще (пре-08-06-2026). Платёж сейчас оплачен и
        // ни разу не реверсился с тех пор, как обсёрвер появился — иначе
        // реверс сам оставил бы аудит-строку, найденную выше. created_at —
        // самый правдоподобный из доступных моментов первой оплаты.
        if (in_array($payment->status, Payment::PAID_STATUSES, true)) {
            return $payment->created_at;
        }

        // Сейчас НЕ оплачен и следа нет — до-аудитный реверс без следа.
        // Правдоподобного момента не существует, остаётся NULL.
        return null;
    }

    private function earliestAuditEnteringPaid(Payment $payment): ?Carbon
    {
        $audits = PaymentAudit::query()
            ->where('payment_id', $payment->id)
            ->oldest('created_at')
            ->get();

        foreach ($audits as $audit) {
            $status = $audit->getAttribute('changes')['status'] ?? null;

            // Как и в hasPriorPaidTransition() (H1645 Step 0 hardening) —
            // смотрим И старое, И новое значение: paid→failed доказывает
            // «был оплачен» тоже, даже если своей «entered paid»-строки
            // (created/pending→paid) в истории нет — например, платёж
            // оплачен ДО появления PaymentAuditObserver (08-06-2026), а
            // отменён уже после.
            $provesPaid = is_array($status)
                ? (in_array($status[0] ?? null, Payment::PAID_STATUSES, true)
                    || in_array($status[1] ?? null, Payment::PAID_STATUSES, true))
                : in_array($status, Payment::PAID_STATUSES, true);

            if ($provesPaid) {
                return $audit->created_at;
            }
        }

        return null;
    }
}
