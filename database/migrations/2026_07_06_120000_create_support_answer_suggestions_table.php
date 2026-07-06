<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Факт-черновик ответа на FAQ (категории A «Zoom/ссылка», B «записи»,
    // C «расписание»), собранный БЕЗ единого LLM-вызова из данных LMS. Куратор в
    // Helpdesk жмёт Принять/Изменить/Отклонить — бот сам НИЧЕГО не отправляет. См. H247.
    public function up(): void
    {
        Schema::create('support_answer_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type'); // chat_message | telegram_support_message
            $table->unsignedBigInteger('source_id');
            $table->string('category', 4); // A | B | C (таксономия ANALYSIS.md)
            $table->text('detected_text')->nullable(); // исходный вопрос студента
            $table->text('draft_text'); // готовый черновик ответа
            $table->json('facts')->nullable(); // резолвленные факты (ссылка, время…)
            $table->float('confidence')->default(0);
            $table->string('status')->default('pending'); // pending|accepted|edited|discarded|expired
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id']);
            $table->index('status');
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_answer_suggestions');
    }
};
