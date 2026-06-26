<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // Автосозданная Zoom-встреча (Server-to-Server OAuth). join_url
            // дублируется в существующее поле `link`, чтобы кабинет показал
            // кнопку «Подключиться» без правок вьюх. start_url — хост-ссылка
            // (только для админа/преподавателя, в кабинет не отдаём).
            if (! Schema::hasColumn('schedules', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable()->index()->after('link');
            }
            if (! Schema::hasColumn('schedules', 'zoom_join_url')) {
                $table->string('zoom_join_url', 1024)->nullable()->after('zoom_meeting_id');
            }
            if (! Schema::hasColumn('schedules', 'zoom_start_url')) {
                $table->text('zoom_start_url')->nullable()->after('zoom_join_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            foreach (['zoom_start_url', 'zoom_join_url', 'zoom_meeting_id'] as $col) {
                if (Schema::hasColumn('schedules', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
