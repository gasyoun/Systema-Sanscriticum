<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4325 (H4310 3/3) — очередь материалов «Мои материалы»: препод присылает
 * видео-анонс/бейдж 4:3/конспект по своему курсу, куратор ведёт заявку через
 * accepted → in_progress → published.
 *
 * Заявка — ЧЕРНОВИК, не витрина: присланное сюда не публикуется само (анти-цель
 * H4325). Реальные поля курса (courses.video_announce_url, course_design_assets
 * формата 4:3, courses.teacher_notes) обновляет только
 * CourseMaterialSubmissionService::publish() при переводе в published — это и
 * есть публикационный гейт куратора.
 *
 * Один открытый (не published) черновик на курс: повторная отправка того же
 * препода обновляет ЭТУ строку, а не плодит дубли — это то самое «обновил
 * материалы», на которое обязана среагировать нотификация куратору.
 * Опубликованная заявка терминальна: следующая отправка того же препода после
 * публикации заводит НОВУЮ строку (новый цикл), поэтому unique-индекс не по
 * course_id целиком, а по (course_id, status) с status-частью в PHP — SQLite в
 * тестах не поддерживает частичный unique-индекс с WHERE, поэтому уникальность
 * «один открытый черновик» держит App\Services\CourseMaterialSubmissionService,
 * а не схема.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_material_submissions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignId('submitted_by_user_id')->constrained('users')->cascadeOnDelete();

            // accepted | in_progress | published — см. CourseMaterialSubmission::STATUSES.
            $table->string('status', 16)->default('accepted');

            // Предложенный видео-анонс: та же ссылка, что публикуется в
            // courses.video_announce_url (H4281 1/3) — YouTube/RuTube/VK,
            // валидируется App\Support\VideoEmbed на форме.
            $table->string('video_announce_url', 500)->nullable();

            // Предложенный бейдж 4:3 — свой файл, НЕ строка course_design_assets:
            // публикация само не происходит, поэтому черновик хранится отдельно
            // от того, что реально отдаёт каталог (H4310 2/3).
            $table->string('badge_disk', 32)->nullable();
            $table->string('badge_path')->nullable();
            $table->string('badge_original_name')->nullable();
            $table->unsignedBigInteger('badge_size')->nullable();
            $table->string('badge_mime', 64)->nullable();
            $table->unsignedInteger('badge_width')->nullable();
            $table->unsignedInteger('badge_height')->nullable();

            // Конспект лекций — свободный текст; публикуется в courses.teacher_notes.
            $table->longText('notes')->nullable();

            $table->foreignId('reviewed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->timestamps();

            $table->index(['course_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_material_submissions');
    }
};
