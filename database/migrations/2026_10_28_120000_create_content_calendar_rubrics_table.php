<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Рубрики календаря контента: расписание (дни недели + время, MSK) и очередь
 * постов. Пост рубрики без даты встаёт на ближайшее свободное время рубрики.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_calendar_rubrics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('prefix')->nullable();
            $table->json('schedule');
            $table->boolean('with_story')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_empty_alert_for')->nullable();
            $table->timestamps();
        });

        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->foreignId('rubric_id')->nullable()->after('slot_type')
                ->constrained('content_calendar_rubrics')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rubric_id');
        });

        Schema::dropIfExists('content_calendar_rubrics');
    }
};
