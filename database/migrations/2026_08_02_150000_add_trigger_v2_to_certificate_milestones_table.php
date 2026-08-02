<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_milestones', function (Blueprint $table) {
            // block | lesson_count | textbook_lesson | material_count | manual.
            // Существующие вехи автоматически становятся block-вехами.
            $table->string('trigger_type', 20)->default('block');
            // lesson_count: N-е занятие; textbook_lesson: номер урока учебника;
            // material_count: N-е занятие с данным material_tag.
            $table->unsignedSmallInteger('trigger_lesson')->nullable();
            // Только lesson_count: повторяющаяся веха, окно (k-1)*R+1..k*R.
            $table->unsignedSmallInteger('repeat_every')->nullable();
            // certificate | spravka («справка об образовании» — тот же макет, другой текст).
            $table->string('document_type', 20)->default('certificate');
            // material_count: какой тег материала считать и по каким ключевым
            // словам стенограммы его авторазмечать (CSV, редактирует куратор —
            // ASR пишет «Эмено» по-разному).
            $table->string('material_tag', 50)->nullable();
            $table->string('material_keywords', 500)->nullable();
        });

        // Блоки обязательны только для block-вех — для остальных типов NULL.
        Schema::table('certificate_milestones', function (Blueprint $table) {
            $table->unsignedTinyInteger('start_block')->nullable()->change();
            $table->unsignedTinyInteger('end_block')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('certificate_milestones', function (Blueprint $table) {
            $table->dropColumn([
                'trigger_type', 'trigger_lesson', 'repeat_every',
                'document_type', 'material_tag', 'material_keywords',
            ]);
        });
    }
};
