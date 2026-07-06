<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Неизменяемость истории рекламных расходов: soft-delete (запись не исчезает
 * физически, cost-per-lead графики восстановимы) + is_locked (защита конкретной
 * записи от правки/удаления). Раньше менеджер мог тихо удалить историю расходов
 * и сломать графики стоимости лида (reporting gap #5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_ad_spends', function (Blueprint $table) {
            $table->softDeletes();
            $table->boolean('is_locked')->default(false)->after('landing_page_id');
        });

        Schema::table('ad_post_spends', function (Blueprint $table) {
            $table->softDeletes();
            $table->boolean('is_locked')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('direct_ad_spends', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('is_locked');
        });

        Schema::table('ad_post_spends', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('is_locked');
        });
    }
};
