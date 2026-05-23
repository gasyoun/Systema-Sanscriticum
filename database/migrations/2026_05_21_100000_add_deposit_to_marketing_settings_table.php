<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Глобальный рубильник «Включить депозиты». Сумма депозита задаётся
            // на уровне курса (см. courses.deposit_amount) — здесь только тогл.
            $table->boolean('deposit_enabled')
                ->default(false)
                ->after('is_loyalty_active');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('deposit_enabled');
        });
    }
};
