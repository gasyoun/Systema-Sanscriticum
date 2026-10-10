<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6327 — постоянная доска прописи (Excalidraw) в кабинете ученика.
 *
 * Сцена хранится как JSON (longtext) на пару «студент × занятие»: доска
 * переживает перезагрузку, перезапуск браузера и смену устройства.
 * Замена webwhiteboard.com, терявшего все доски каждые 24 часа.
 *
 * Cap + prune (риски из handoff): сцена ограничена 2 МБ на контроллере
 * (413 сверху); история студента не удаляется автоматически — экспорт
 * в PDF/файл силами самого Excalidraw остаётся основным «архивом».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devanagari_boards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained('lessons')->nullOnDelete();
            $table->string('title', 120)->default('Прописи');
            $table->longText('scene')->nullable();
            $table->unsignedInteger('elements_count')->default(0);
            $table->timestamps();

            $table->index(['student_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devanagari_boards');
    }
};
