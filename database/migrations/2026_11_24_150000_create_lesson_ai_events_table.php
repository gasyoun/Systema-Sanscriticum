<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал запросов к ИИ-помощнику занятия (тест «Спроси Сократа», 24-11-2026).
 *
 * Тексты (вопрос, выделенный фрагмент, ответ) пишутся ТОЛЬКО тому, кто дал
 * согласие ai_test и включен тестировщиком: App\Services\Knowledge\LessonAiEventLog
 * проверяет оба условия на каждой записи. Для остальных остаются счетчики:
 * задача, статус, время, длина заметок. Заметки к занятию текстом не хранятся
 * никогда — только их длина (notes_chars).
 *
 * Срок хранения — config('lesson_ai.events.retention_days'), чистит
 * `lesson-ai:purge-events` ежедневно: журнал не растет бесконечно, а личные
 * тексты не лежат в базе годами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lesson_ai_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lesson_id')->nullable()->index();
            $table->string('task', 32);
            $table->string('status', 16);                    // done | error
            $table->string('source', 16)->default('model');  // model | cached
            $table->string('error_code', 32)->nullable();
            $table->string('question', 500)->nullable();
            $table->string('selection', 1000)->nullable();
            $table->mediumText('answer_md')->nullable();
            $table->unsignedSmallInteger('notes_chars')->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['user_id', 'created_at']);
            $table->index(['task', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_ai_events');
    }
};
