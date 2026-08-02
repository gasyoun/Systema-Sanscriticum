<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // Заголовок в самом сертификате (снапшот в certificates.course_title,
            // «|» = перенос строки, как в ручной форме). Пусто → course.title.
            $table->string('certificate_title')->nullable();
            $table->string('template')->default('gasuns');
            // Номера CourseBlock.number; триггер автовыдачи = ends_at блока end_block.
            $table->unsignedTinyInteger('start_block');
            $table->unsignedTinyInteger('end_block');
            $table->boolean('auto_issue_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['course_id', 'start_block', 'end_block']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_milestones');
    }
};
