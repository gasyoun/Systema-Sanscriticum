<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Баллы экзамена «Санка» ДО выдачи сертификата: преподаватель ведёт их
        // в Google-таблице, n8n затягивает вебхуком /api/webhooks/exam-scores.
        // Пересдача перезаписывает строку; выданный сертификат — замороженный
        // снапшот, обновление баллов его не трогает.
        Schema::create('exam_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->decimal('score_clarity', 4, 1)->nullable();
            $table->decimal('score_letters', 4, 1)->nullable();
            $table->decimal('score_flow', 4, 1)->nullable();
            // Ссылка на строку таблицы из n8n («Лист1!A7») — для разбора инцидентов.
            $table->string('source_row', 64)->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_scores');
    }
};
