<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Both-sides referral (MG 02-08-2026): referrer keeps `amount`; referred may get
 * `referred_amount` when config('referral.referred_credit_amount') > 0 (dark default 0).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referral_rewards', function (Blueprint $table) {
            $table->decimal('referred_amount', 10, 2)
                ->default(0)
                ->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('referral_rewards', function (Blueprint $table) {
            $table->dropColumn('referred_amount');
        });
    }
};
