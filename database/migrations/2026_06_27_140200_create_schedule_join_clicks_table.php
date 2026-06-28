<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_join_clicks', function (Blueprint $table) {
            // Надёжная привязка «студент → занятие»: клик по ссылке «Подключиться»
            // в кабинете (авторизован) или боте (подписанная ссылка с user id).
            // Даёт личность/намерение; минуты присутствия — из webinar_attendances.
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source')->default('cabinet'); // cabinet | telegram | vk
            $table->timestamp('first_clicked_at')->nullable();
            $table->timestamp('last_clicked_at')->nullable();
            $table->unsignedInteger('click_count')->default(0);
            $table->timestamps();

            // Одна строка на студента в рамках занятия.
            $table->unique(['schedule_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_join_clicks');
    }
};
