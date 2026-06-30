<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Мягкий выход студента из группы: членство остаётся (доступ/листинг в ЛК
 * завязаны на group_user), но участник скрыт из «активного состава».
 *  - left_at      NULL = активный участник; заполнено = вышел/архив.
 *  - left_reason  'status' (авто по терминальному статусу курса) | 'manual'
 *                 (ручной тумблер). Ручной приоритетнее авто.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_user', function (Blueprint $table) {
            $table->timestamp('left_at')->nullable()->after('group_id');
            $table->string('left_reason')->nullable()->after('left_at');
        });
    }

    public function down(): void
    {
        Schema::table('group_user', function (Blueprint $table) {
            $table->dropColumn(['left_at', 'left_reason']);
        });
    }
};
