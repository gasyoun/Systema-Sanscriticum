<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Разовый бэкфилл `payments.first_paid_at` (H1645, резервная опора
 * resurrection-guard'а — см. Payment::hasPriorPaidTransition()).
 *
 * Источники (первое совпадение побеждает):
 *   (a) payment_audits — самая ранняя запись, где статус (старое ИЛИ новое
 *       значение диффа) входит в PAID_STATUSES. Пост-обсерверная история
 *       (с 08-06-2026) авторитетна и покрывает как «создан оплаченным», так
 *       и «стал оплаченным позже», в т.ч. для платежей, впоследствии
 *       отменённых.
 *   (b) платёж СЕЙЧАС оплачен, но аудит-следа с paid-эвиденцией нет (до-
 *       аудитная эпоха, ни разу не отменялся) — берём created_at платежа как
 *       единственную достоверную нижнюю границу.
 *
 * Остаток, который НЕЛЬЗЯ восстановить: платёж сейчас НЕ оплачен и аудит не
 * содержит paid-эвиденции — значит, он был оплачен и отменён целиком до
 * 08-06-2026, не оставив следа. Остаётся null; количество (не построчно)
 * печатается в выводе команды.
 *
 * Идемпотентна: обрабатывает только payments.first_paid_at IS NULL, никогда
 * не перезаписывает уже проставленное значение. Dry-run по умолчанию —
 * миграцию/бэкфилл на прод накатывает только Иван (`--apply`, DEPLOY_QUEUE).
 */
class BackfillFirstPaidAt extends Command
{
    protected $signature = 'payments:backfill-first-paid-at {--apply : Записать изменения (по умолчанию — dry-run)}';

    protected $description = 'Бэкфилл payments.first_paid_at из payment_audits / created_at (H1645, dry-run по умолчанию).';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $toSet = [];
        $residualNoTrace = 0;

        Payment::query()
            ->whereNull('first_paid_at')
            ->with('audits')
            ->chunkById(500, function ($payments) use (&$toSet, &$residualNoTrace) {
                foreach ($payments as $payment) {
                    $earliest = $this->earliestPaidMoment($payment);

                    if ($earliest !== null) {
                        $toSet[$payment->id] = $earliest;

                        continue;
                    }

                    if (in_array($payment->status, Payment::PAID_STATUSES, true)) {
                        $toSet[$payment->id] = $payment->created_at;

                        continue;
                    }

                    $residualNoTrace++;
                }
            });

        $this->info(sprintf('Платежей к бэкфиллу: %d', count($toSet)));
        $this->info(sprintf(
            'Остаток без следа (отменён до 08-06-2026, first_paid_at останется null): %d',
            $residualNoTrace
        ));

        if (! $apply) {
            $this->comment('Dry-run — изменения не записаны. Передайте --apply, чтобы записать.');

            return self::SUCCESS;
        }

        Payment::withoutEvents(function () use ($toSet) {
            foreach ($toSet as $id => $timestamp) {
                Payment::query()
                    ->whereKey($id)
                    ->whereNull('first_paid_at')
                    ->update(['first_paid_at' => $timestamp]);
            }
        });

        $this->info(sprintf('Забэкфилено: %d', count($toSet)));

        return self::SUCCESS;
    }

    /** Самая ранняя запись аудита с paid-эвиденцией (по любой стороне диффа) — или null. */
    private function earliestPaidMoment(Payment $payment): ?Carbon
    {
        $earliest = null;

        foreach ($payment->audits as $audit) {
            $status = $audit->getAttribute('changes')['status'] ?? null;

            $isPaidEvidence = is_array($status)
                ? (in_array($status[0] ?? null, Payment::PAID_STATUSES, true)
                    || in_array($status[1] ?? null, Payment::PAID_STATUSES, true))
                : in_array($status, Payment::PAID_STATUSES, true);

            if (! $isPaidEvidence || $audit->created_at === null) {
                continue;
            }

            if ($earliest === null || $audit->created_at->lt($earliest)) {
                $earliest = $audit->created_at;
            }
        }

        return $earliest;
    }
}
