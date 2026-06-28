<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Единая постоянная ссылка на Zoom-конференцию курса (recurring-встреча
            // / личная комната), создаётся вручную на zoom.us. Генератор расписания
            // копирует её в каждое занятие курса.
            if (! Schema::hasColumn('courses', 'zoom_link')) {
                $table->string('zoom_link', 1024)->nullable()->after('chat_url');
            }

            // Числовой meeting_id, извлечённый из zoom_link (.../j/{id}). Нужен для
            // резолва посещаемости: вебхук/Reports API приходят с этим id, общим
            // для всех дат, дальше разруливаем по occurrence uuid + времени.
            if (! Schema::hasColumn('courses', 'zoom_meeting_id')) {
                $table->string('zoom_meeting_id')->nullable()->index()->after('zoom_link');
            }
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            foreach (['zoom_meeting_id', 'zoom_link'] as $column) {
                if (Schema::hasColumn('courses', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
