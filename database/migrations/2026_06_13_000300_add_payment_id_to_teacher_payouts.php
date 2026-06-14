<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь выплаты преподавателю с её транзакцией-зеркалом в «Финансах»
 * (payments.tariff='salary_payout'). nullOnDelete: удалили транзакцию вручную —
 * выплата остаётся, просто считается «не проведённой».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->foreignId('payment_id')->nullable()->after('breakdown')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_id');
        });
    }
};
