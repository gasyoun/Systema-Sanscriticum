<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Детектор «курс идёт, а вехи сертификатов не настроены».
            $table->boolean('course_missing_milestones_notify_enabled')->default(true);
            $table->unsignedSmallInteger('course_missing_milestones_threshold')->default(12);
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'course_missing_milestones_notify_enabled',
                'course_missing_milestones_threshold',
            ]);
        });
    }
};
