<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            // Запись вебинара, прилетевшая Zoom-вебхуком recording.completed (Фаза 2).
            if (! Schema::hasColumn('schedules', 'zoom_recording_url')) {
                $table->string('zoom_recording_url', 1024)->nullable()->after('zoom_start_url');
            }
            if (! Schema::hasColumn('schedules', 'zoom_recording_received_at')) {
                $table->timestamp('zoom_recording_received_at')->nullable()->after('zoom_recording_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            foreach (['zoom_recording_received_at', 'zoom_recording_url'] as $col) {
                if (Schema::hasColumn('schedules', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
