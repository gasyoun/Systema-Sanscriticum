<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Плашки занятий — шаблон курса (или группы).
 *
 * Шаблон = фон (PNG, композит PSD без слоёв даты и номера) + spec: где и каким
 * шрифтом рисовать дату и номер. PSD разбирается ОДИН раз при заведении
 * шаблона (scripts/banner_template_from_psd.py), рендер к нему не ходит.
 *
 * Резолв: шаблон группы (group_id задан) бьёт шаблон курса (group_id пуст).
 * `version` растёт при каждой замене фона/spec — от неё зависит render_hash
 * плашки, так что смена шаблона перерисовывает все будущие плашки сама.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_banner_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
            $table->string('background_disk');
            $table->string('background_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->json('spec');
            $table->string('psd_disk')->nullable();
            $table->string('psd_path')->nullable();
            $table->string('psd_original_name')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Автоимя (course_id, group_id) влезает в 64 символа MySQL, но
            // задаём явно — см. урок H5065 про ошибку 1059.
            $table->index(['course_id', 'group_id'], 'lesson_banner_tpl_course_group_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_banner_templates');
    }
};
