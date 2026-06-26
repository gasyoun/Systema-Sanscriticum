<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Серия активных дней подряд (геймификация): streak_days растёт на +1 за
            // каждый новый день активности, сбрасывается при пропуске. На вехах
            // (config('prana.streak_bonuses')) начисляется бонус праны.
            if (! Schema::hasColumn('users', 'streak_days')) {
                $table->unsignedInteger('streak_days')->default(0)->after('referral_credit');
            }
            if (! Schema::hasColumn('users', 'streak_last_day')) {
                $table->date('streak_last_day')->nullable()->after('streak_days');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['streak_last_day', 'streak_days'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
