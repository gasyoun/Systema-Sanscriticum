<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two-counter prana: prana_balance — тратимый кошелёк-скидка (как сейчас),
     * lifetime_prana — накопленный «опыт/карма», только растёт и определяет ранги
     * (Śiṣya→Paṇḍita); тратами не уменьшается. Бэкфилл = сумма всех начислений
     * (положительных транзакций) из истории.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'lifetime_prana')) {
            Schema::table('users', function (Blueprint $table) {
                $table->unsignedBigInteger('lifetime_prana')->default(0)->after('prana_balance');
            });
        }

        DB::statement(
            'UPDATE users SET lifetime_prana = ('.
            'SELECT COALESCE(SUM(amount), 0) FROM prana_transactions '.
            'WHERE prana_transactions.user_id = users.id AND amount > 0)'
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'lifetime_prana')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('lifetime_prana');
            });
        }
    }
};
