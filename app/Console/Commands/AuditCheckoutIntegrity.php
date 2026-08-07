<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PaymentWebhookEvent;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Money-invariant auditor (checkout integrity).
 *
 * Dry-run by default. With any non-empty measured bucket the command returns
 * FAILURE so a scheduled run pages via Kernel onFailure (H2338 / audit spec 6).
 * Safe repairs (--apply-safe) only recalculate promo used_count, and only when
 * features.checkout_integrity_safe_repairs is ON.
 */
class AuditCheckoutIntegrity extends Command
{
    protected $signature = 'payments:audit-checkout-integrity
                            {--apply-safe : Recalculate promo used_count from paid, non-conditional payments}';

    protected $description = 'Audit checkout money invariants; dry-run unless --apply-safe is explicitly enabled; FAILURE when any bucket is non-empty';

    public function handle(): int
    {
        $applySafe = (bool) $this->option('apply-safe');

        if ($applySafe && ! config('features.checkout_integrity_safe_repairs')) {
            $this->error('Safe repairs are disabled. Set CHECKOUT_INTEGRITY_SAFE_REPAIRS=true and rebuild config cache.');

            return self::FAILURE;
        }

        $this->info($applySafe ? 'Mode: APPLY SAFE (promo counters only)' : 'Mode: DRY RUN (no writes)');

        $negativeWallets = $this->negativeReferralWallets();
        $strandedDeposits = $this->reversedOrdersWithConsumedDeposits();
        $promoMismatches = $this->promoCounterMismatches();
        $legacyReservations = $this->legacyPendingPromoReservations();
        $rejectedWebhooks = $this->rejectedWebhookDeliveries();
        $paidNoGroup = $this->paidButNoGroupMembership();

        $this->report(
            'Negative referral wallets',
            ['user_id', 'referral_credit'],
            $negativeWallets,
        );
        $this->report(
            'Reversed orders with stranded deposit credit',
            ['payment_id', 'user_id', 'course_id', 'applied', 'consumed_total'],
            $strandedDeposits,
        );
        $this->report(
            'Promo counter mismatches',
            ['promo_id', 'code', 'stored', 'expected'],
            $promoMismatches,
        );
        $this->report(
            'Legacy pending promo links without expiry',
            ['payment_id', 'user_id', 'promo_id', 'created_at'],
            $legacyReservations,
        );
        $this->report(
            'Rejected webhook deliveries',
            ['event_id', 'payment_id', 'decision', 'bank_status', 'reported_amount', 'created_at'],
            $rejectedWebhooks,
        );
        $this->report(
            'Paid but no course group membership',
            ['payment_id', 'user_id', 'course_id', 'tariff', 'amount'],
            $paidNoGroup,
        );

        if ($applySafe) {
            $updated = $this->recalculatePromoCounters();
            $this->info("Applied promo counters: {$updated}");
            // Re-measure promo bucket after repair so exit code reflects residual issues only.
            $promoMismatches = $this->promoCounterMismatches();
            $this->line("Promo counter mismatches (after apply-safe): {$promoMismatches->count()}");
        }

        $this->warn(
            'Manual bank/accounting review is required before fixing negative wallets, restoring historical deposits, or canceling legacy pending links.'
        );

        $bucketCounts = [
            'Negative referral wallets' => $negativeWallets->count(),
            'Reversed orders with stranded deposit credit' => $strandedDeposits->count(),
            'Promo counter mismatches' => $promoMismatches->count(),
            'Legacy pending promo links without expiry' => $legacyReservations->count(),
            'Rejected webhook deliveries' => $rejectedWebhooks->count(),
            'Paid but no course group membership' => $paidNoGroup->count(),
        ];

        $dirty = array_filter($bucketCounts, static fn (int $n): bool => $n > 0);

        if ($dirty === []) {
            $this->info('All integrity buckets empty — OK.');

            return self::SUCCESS;
        }

        $this->error('Integrity buckets non-empty (FAILURE):');
        foreach ($dirty as $label => $count) {
            $this->line("  • {$label}: {$count}");
        }

        if ($paidNoGroup->isNotEmpty()) {
            $ids = $paidNoGroup->pluck(0)->implode(', ');
            $this->error("Paid-but-no-group payment id(s): {$ids}");
        }

        return self::FAILURE;
    }

    private function negativeReferralWallets(): Collection
    {
        return User::query()
            ->where('referral_credit', '<', 0)
            ->orderBy('id')
            ->get(['id', 'referral_credit'])
            ->map(fn (User $user): array => [
                $user->id,
                number_format((float) $user->referral_credit, 2, '.', ''),
            ]);
    }

    private function reversedOrdersWithConsumedDeposits(): Collection
    {
        return Payment::query()
            ->whereIn('status', ['failed', 'canceled', 'cancelled'])
            ->where('deposit_credit_applied', '>', 0)
            ->whereNotNull('user_id')
            ->whereNotNull('course_id')
            ->orderBy('id')
            ->get(['id', 'user_id', 'course_id', 'deposit_credit_applied'])
            ->map(function (Payment $payment): ?array {
                $consumed = (float) Payment::query()
                    ->where('user_id', $payment->user_id)
                    ->where('course_id', $payment->course_id)
                    ->whereIn('tariff', ['deposit', 'trial'])
                    ->paid()
                    ->real()
                    ->sum('consumed_amount');

                if ($consumed <= 0) {
                    return null;
                }

                return [
                    $payment->id,
                    $payment->user_id,
                    $payment->course_id,
                    number_format((float) $payment->deposit_credit_applied, 2, '.', ''),
                    number_format($consumed, 2, '.', ''),
                ];
            })
            ->filter()
            ->values();
    }

    private function promoCounterMismatches(): Collection
    {
        $expected = $this->expectedPromoCounts();

        return PromoCode::query()
            ->orderBy('id')
            ->get(['id', 'code', 'used_count'])
            ->map(function (PromoCode $promo) use ($expected): ?array {
                $paidCount = (int) ($expected[$promo->id] ?? 0);
                if ($promo->used_count === $paidCount) {
                    return null;
                }

                return [$promo->id, $promo->code, $promo->used_count, $paidCount];
            })
            ->filter()
            ->values();
    }

    private function legacyPendingPromoReservations(): Collection
    {
        return Payment::query()
            ->whereNotNull('promo_code_id')
            ->where('status', 'pending')
            ->whereNull('payment_link_expires_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'promo_code_id', 'created_at'])
            ->map(fn (Payment $payment): array => [
                $payment->id,
                $payment->user_id,
                $payment->promo_code_id,
                $payment->created_at?->toDateTimeString(),
            ]);
    }

    /**
     * Отклонённые денежные вебхуки (H1359) — чтобы операторы видели воскрешения
     * и расхождения сумм без доступа к БД. Только чтение журнала.
     */
    private function rejectedWebhookDeliveries(): Collection
    {
        return PaymentWebhookEvent::query()
            ->whereIn('decision', PaymentWebhookEvent::REJECTED_DECISIONS)
            ->orderBy('id')
            ->get(['id', 'payment_id', 'decision', 'bank_status', 'reported_amount', 'created_at'])
            ->map(fn (PaymentWebhookEvent $event): array => [
                $event->id,
                $event->payment_id,
                $event->decision,
                $event->bank_status,
                $event->reported_amount === null
                    ? ''
                    : number_format((float) $event->reported_amount, 2, '.', ''),
                $event->created_at?->toDateTimeString(),
            ]);
    }

    /**
     * Paid non-deposit payment whose user is not a member of any group linked
     * to the purchased course (course_group ↔ group_user). Residue of silent
     * grantAccess on group-less courses and import paths (H2304 / audit #1, #15).
     */
    private function paidButNoGroupMembership(): Collection
    {
        return Payment::query()
            ->paid()
            ->whereNotNull('user_id')
            ->whereNotNull('course_id')
            ->whereNotIn('tariff', ['deposit', 'trial'])
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('group_user')
                    ->join('course_group', 'course_group.group_id', '=', 'group_user.group_id')
                    ->whereColumn('group_user.user_id', 'payments.user_id')
                    ->whereColumn('course_group.course_id', 'payments.course_id');
            })
            ->orderBy('id')
            ->get(['id', 'user_id', 'course_id', 'tariff', 'amount'])
            ->map(fn (Payment $payment): array => [
                $payment->id,
                $payment->user_id,
                $payment->course_id,
                $payment->tariff,
                number_format((float) $payment->amount, 2, '.', ''),
            ]);
    }

    private function expectedPromoCounts(): Collection
    {
        return Payment::query()
            ->selectRaw('promo_code_id, COUNT(*) AS paid_count')
            ->whereNotNull('promo_code_id')
            ->paid()
            ->real()
            ->groupBy('promo_code_id')
            ->pluck('paid_count', 'promo_code_id');
    }

    private function recalculatePromoCounters(): int
    {
        return DB::transaction(function (): int {
            $updated = 0;

            $promos = PromoCode::query()->orderBy('id')->lockForUpdate()->get();
            $expected = $this->expectedPromoCounts();
            foreach ($promos as $promo) {
                $paidCount = (int) ($expected[$promo->id] ?? 0);
                if ($promo->used_count === $paidCount) {
                    continue;
                }

                $promo->updateQuietly(['used_count' => $paidCount]);
                $updated++;
            }

            return $updated;
        });
    }

    private function report(string $title, array $headers, Collection $rows): void
    {
        $this->line("{$title}: {$rows->count()}");

        if ($rows->isNotEmpty()) {
            $this->table($headers, $rows->all());
        }
    }
}
