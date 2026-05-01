<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lecture_drafts', function (Blueprint $table) {
            $table->id();

            $table->string('slug')->unique();
            $table->string('title');

            $table->enum('status', [
                'draft',
                'preprocessing',
                'editing',
                'built',
                'published',
            ])->default('draft')->index();

            $table->foreignId('course_id')
                ->nullable()
                ->constrained('courses')
                ->nullOnDelete();

            $table->foreignId('lesson_id')
                ->nullable()
                ->constrained('lessons')
                ->nullOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Пути относительно storage/app/
            $table->string('data_json_path')->nullable();
            $table->string('output_html_path')->nullable();
            $table->string('slides_dir')->nullable();

            // meta лекции (lecturer, course, video, period, …)
            $table->json('meta')->nullable();

            // Лог последней операции (preprocess / build) — для диагностики
            $table->text('error_log')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lecture_drafts');
    }
};
