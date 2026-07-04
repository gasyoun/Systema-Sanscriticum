<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Помечает платежи, созданные должником самостоятельно из кабинета
            // (DebtPaymentController) — и promise-оплаты, и бандл плоского долга.
            // Нужен для анти-дубль-гарда (один незавершённый self-service заказ на
            // курс) и для будущей аналитики самообслуживания.
            $table->boolean('is_self_service')
                ->default(false)
                ->after('is_conditional');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('is_self_service');
        });
    }
};
