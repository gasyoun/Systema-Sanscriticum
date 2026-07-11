<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GC-B3 / H601: vendor-агностичные `meeting_*` алиасы поверх `zoom_*`
 * (WebinarProvider seam). Сгенерированные (GENERATED ALWAYS AS ... VIRTUAL)
 * колонки, а не физическая копия — исключает рассинхрон значений, реверсивны
 * простым dropColumn, никакого бэкфилла не требуется. `zoom_*` остаются
 * основным хранилищем на время перехода (см. handoff §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('schedules', 'meeting_id')) {
                // Без ->index(): индекс на VIRTUAL generated-колонке требует SQLite
                // >= 3.31 — небезопасное допущение для тестового окружения.
                // Существующий индекс на zoom_meeting_id уже покрывает поиск.
                $table->string('meeting_id')->nullable()->virtualAs('zoom_meeting_id')->after('zoom_meeting_id');
            }
            if (! Schema::hasColumn('schedules', 'meeting_join_url')) {
                $table->string('meeting_join_url', 1024)->nullable()->virtualAs('zoom_join_url')->after('zoom_join_url');
            }
            if (! Schema::hasColumn('schedules', 'meeting_start_url')) {
                $table->text('meeting_start_url')->nullable()->virtualAs('zoom_start_url')->after('zoom_start_url');
            }
            if (! Schema::hasColumn('schedules', 'meeting_recording_url')) {
                $table->string('meeting_recording_url', 1024)->nullable()->virtualAs('zoom_recording_url')->after('zoom_recording_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            foreach (['meeting_recording_url', 'meeting_start_url', 'meeting_join_url', 'meeting_id'] as $col) {
                if (Schema::hasColumn('schedules', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
