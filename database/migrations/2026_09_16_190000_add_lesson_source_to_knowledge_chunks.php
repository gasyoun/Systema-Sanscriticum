<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Этап 4 (лекции в RAG) — расширение схемы H4001 под `source_type`, ровно то,
 * что docs/EXPERIMENT_OLLAMA_GPU_OCT1_2026.md называл предусловием: «потребует
 * расширения схемы knowledge_chunks под source_type/audience».
 *
 * Что добавляется и почему:
 *  - source_type — единственный разделитель полос. FAQ-нога обязана остаться
 *    байт-в-байт прежней, поэтому и она, и индексатор FAQ фильтруют по 'faq';
 *  - lesson_id / course_id — область видимости чанка. course_id строковый:
 *    lessons.course_id в этой схеме varchar, а не bigint (историческое);
 *  - start_seconds — таймкод начала фрагмента, из него собирается ссылка
 *    на минуту записи в ответе бота;
 *  - text — сам фрагмент. У FAQ текст берётся из файла корпуса при каждом
 *    запросе, у урока такого файла нет: без колонки пришлось бы на каждый
 *    вопрос перечитывать и заново резать транскрипт с приватного диска.
 *
 * `audience` СОЗНАТЕЛЬНО не заводится: доступ студента к уроку считается
 * вживую через LessonGate (тот же гейт, что у выдачи стенограммы), а копия
 * прав в индексе была бы вторым источником правды и протухала бы молча.
 *
 * Внешнего ключа на lessons нет намеренно: тесты идут на SQLite, где FK через
 * ALTER TABLE не добавляется. Осиротевшие чанки чистит сам индексатор
 * (knowledge:index-lessons), и на это есть тест.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->string('source_type', 16)->default('faq')->after('faq_chunk_id');
            $table->unsignedBigInteger('lesson_id')->nullable()->after('source_type');
            $table->string('course_id')->nullable()->after('lesson_id');
            $table->unsignedInteger('start_seconds')->nullable()->after('course_id');
            $table->mediumText('text')->nullable()->after('content_hash');

            $table->index(['source_type', 'lesson_id'], 'knowledge_chunks_source_lesson_idx');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_chunks', function (Blueprint $table) {
            $table->dropIndex('knowledge_chunks_source_lesson_idx');
            $table->dropColumn(['source_type', 'lesson_id', 'course_id', 'start_seconds', 'text']);
        });
    }
};
