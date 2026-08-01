<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\PromoCode;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ExpireStaleCheckouts extends Command
{
    protected $signature = 'payments:expire-stale-checkouts
                            {--apply : Actually cancel stale checkouts; without this, only reports what would be cancelled}';

    protected $description = 'Fail abandoned pending checkouts past their timeout, releasing spent prana, applied referral credit, consumed deposits and promo reservations via the existing Payment reversal observer (Payment::booted). Dry-run unless --apply is explicitly enabled.';

    /**
     * These rows are deliberately never touched by the reaper:
     * - deposit/trial: not abandoned checkouts, they ARE the held reservation
     *   (Payment::scopeUnconsumedDeposits reads them once paid); a pending one
     *   simply hasn't been paid yet and carries no separate reservation to release.
     * - paypal / invoice: await manual reconciliation in the admin
     *   (Payment::MANUAL_CLAIM_PROVIDERS — PayPal claim + company invoice).
     * - conditional: zero-money "under promise" grants are always created directly
     *   as paid (see ConditionalAccessGranter) and should never appear pending, but
     *   excluded defensively.
     */
    private function excludedFromReaping($query): void
    {
        $query
            ->whereNotIn('tariff', ['deposit', 'trial'])
            ->where(function ($provider): void {
                $provider->whereNull('provider')
                    ->orWhereNotIn('provider', Payment::MANUAL_CLAIM_PROVIDERS);
            })
            ->where('is_conditional', false);
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        if ($apply && ! config('features.checkout_stale_order_expiry')) {
            // Exit 0 when called from a stale schedule or a mistaken --apply: do not
            // poison schedule:run / laravel.log with ERROR every 15 minutes. The
            // reaper still refuses to mutate rows (no candidates are applied).
            $this->warn('Reaper is disabled. Set CHECKOUT_STALE_ORDER_EXPIRY=true and rebuild config cache, or omit --apply to preview.');

            return self::SUCCESS;
        }

        $staleIds = $this->staleCandidateIds();

        $this->info(($apply ? 'Failing' : 'DRY RUN — would fail').' '.$staleIds->count().' stale pending checkout(s).');

        if (! $apply) {
            if ($staleIds->isNotEmpty()) {
                $this->table(['payment_id'], $staleIds->map(fn (int $id): array => [$id])->all());
            }

            return self::SUCCESS;
        }

        $failed = 0;
        foreach ($staleIds as $id) {
            if ($this->failIfStillStale($id)) {
                $failed++;
            }
        }

        $this->info("Failed {$failed} stale pending checkout(s); prana/referral credit/deposits/promo slots released via the standard reversal path.");

        return self::SUCCESS;
    }

    /**
     * Read-only pass to find candidate pending payments whose checkout window
     * has definitely closed. Timed promo reservations use PromoCode's own TTL +
     * webhook buffer as authoritative (mirrors PromoCode::activeReservationCount);
     * null-expiry rows (every non-promo checkout, plus promo checkouts made while
     * checkout_promo_reservations was off) use checkout.legacy_pending_days.
     */
    private function staleCandidateIds(): Collection
    {
        $legacyThreshold = now()->subDays((int) config('checkout.legacy_pending_days', 30));
        $promoBuffer = now()->subMinutes(PromoCode::WEBHOOK_BUFFER_MINUTES);

        $query = Payment::query()
            ->where('status', 'pending')
            ->where(function ($query) use ($legacyThreshold, $promoBuffer): void {
                $query
                    ->where('payment_link_expires_at', '<=', $promoBuffer)
                    ->orWhere(function ($noTimedReservation) use ($legacyThreshold): void {
                        $noTimedReservation
                            ->whereNull('payment_link_expires_at')
                            ->where('created_at', '<=', $legacyThreshold);
                    });
            });

        $this->excludedFromReaping($query);

        return $query->orderBy('id')->pluck('id');
    }

    /**
     * Re-check + flip under a row lock inside a transaction, mirroring
     * WebhookController's idempotency pattern (Payment::lockForUpdate inside
     * DB::transaction) so a bank webhook landing in the same instant always
     * wins the race instead of getting reaped.
     */
    private function failIfStillStale(int $paymentId): bool
    {
        return DB::transaction(function () use ($paymentId): bool {
            $payment = Payment::lockForUpdate()->find($paymentId);

            if (! $payment || $payment->status !== 'pending') {
                return false;
            }

            if (in_array($payment->tariff, ['deposit', 'trial'], true)
                || in_array($payment->provider, Payment::MANUAL_CLAIM_PROVIDERS, true)
                || $payment->is_conditional
            ) {
                return false;
            }

            $legacyThreshold = now()->subDays((int) config('checkout.legacy_pending_days', 30));
            $promoBuffer = now()->subMinutes(PromoCode::WEBHOOK_BUFFER_MINUTES);

            $stillStale = $payment->payment_link_expires_at !== null
                ? $payment->payment_link_expires_at->lte($promoBuffer)
                : $payment->created_at->lte($legacyThreshold);

            if (! $stillStale) {
                return false;
            }

            // Payment::booted()'s updated listener does the actual
            // prana/referral-credit/deposit/promo reversal work on any
            // pending -> failed/canceled transition; this command's only job
            // is to trigger it for silently-abandoned orders.
            $payment->update(['status' => 'failed']);

            return true;
        });
    }
}
