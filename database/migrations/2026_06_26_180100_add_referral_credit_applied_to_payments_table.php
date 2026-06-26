<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Сколько реферального кредита зачтено в этот платёж — для аудита и
            // возврата в кошелёк, если оплата сорвалась (как prana_spent).
            if (! Schema::hasColumn('payments', 'referral_credit_applied')) {
                $table->decimal('referral_credit_applied', 10, 2)->nullable()->after('prana_spent');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'referral_credit_applied')) {
                $table->dropColumn('referral_credit_applied');
            }
        });
    }
};
