<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тестировщики ИИ-помощника занятия: пока features.lesson_ai выключен,
 * помощник видят суперадмин и отмеченные здесь студенты (назначает
 * суперадмин в карточке пользователя).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('lesson_ai_tester')->default(false)->after('is_lecture_editor');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('lesson_ai_tester');
        });
    }
};
