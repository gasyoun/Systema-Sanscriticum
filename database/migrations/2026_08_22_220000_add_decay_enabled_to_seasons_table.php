<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H3297 — DB-driven decay override (закрытие @DECIDE из §9
     * PLAN_SYSTEMA_SEASON_LIVE_SERVICE_SEPT_2026, дефолт по умолчанию:
     * .env-правки на проде требуют deploy-цикла, а cron стреляет без присмотра).
     *
     * Флаг живёт на строке сезона: season:open ставит true,
     * season:close гасит обратно (R4-1 — decay не живёт вне сезона).
     * PranaService читает его ДО .env (см. isDecayEnabled()).
     */
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->boolean('decay_enabled')->default(false)->after('rewards_config');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('decay_enabled');
        });
    }
};
