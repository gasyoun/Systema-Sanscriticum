<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Реферальный денежный кошелёк: начисляется за оплату приглашённого,
            // автоматически зачитывается в стоимость следующей покупки.
            if (! Schema::hasColumn('users', 'referral_credit')) {
                $table->decimal('referral_credit', 10, 2)->default(0)->after('prana_balance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'referral_credit')) {
                $table->dropColumn('referral_credit');
            }
        });
    }
};
