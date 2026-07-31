<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Structured claim payload for semi-manual payment paths:
 * - PayPal: sender account, paid_on date, txn id (admin reconciles personal PayPal).
 * - Company invoice: legal name, INN, KPP, address (admin reconciles bank transfer).
 *
 * Additive JSON; null for Tochka/card/SBP rows. Does not change money/access
 * behaviour until a human flips pending → paid in Filament.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->json('claim_meta')->nullable()->after('payer_note');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('claim_meta');
        });
    }
};
