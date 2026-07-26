<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H1645 — closes the resurrection-guard audit blind spot (H1405 Wave 2 C3,
 * docs/money-access-core-manual.md §9 C3).
 *
 * `hasPriorPaidTransition()` (H1359 guard b) reads `payment_audits`, which only
 * exists since 08-06-2026 and is silently skipped by every `withoutEvents`
 * create-as-paid path (silent PromiseFulfillment, access-only siblings,
 * conditional grants) — so a payment paid-then-reversed before that date, or
 * created paid without an audit snapshot, is invisible to the resurrection
 * guard. `first_paid_at` is a direct, observer-independent stamp of the first
 * moment a payment entered a paid status; it is backfilled (see the paired
 * artisan command) and never overwritten once set.
 *
 * Additive and nullable: this migration ships in the PR only — an agent never
 * runs it against prod (programme ruling D16); Ivan applies it via the
 * DEPLOY_QUEUE row added alongside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->timestamp('first_paid_at')->nullable()->after('status');
            $table->index('first_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['first_paid_at']);
            $table->dropColumn('first_paid_at');
        });
    }
};
