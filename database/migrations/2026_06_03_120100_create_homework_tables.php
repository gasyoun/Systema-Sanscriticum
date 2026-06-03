<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('homework_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            // draft | submitted | needs_revision | accepted
            $table->string('status')->default('draft');
            $table->timestamp('last_activity_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'lesson_id']);
            $table->index(['course_id', 'status']);
        });

        Schema::create('homework_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained('homework_submissions')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // student | teacher | admin
            $table->string('author_role');
            // submission | review | message
            $table->string('type')->default('message');
            $table->text('body')->nullable();
            // статус, выставленный этим review-комментарием (для истории)
            $table->string('new_status')->nullable();
            $table->timestamps();

            $table->index('submission_id');
        });

        Schema::create('homework_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('homework_comments')->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime')->nullable();
            $table->timestamps();

            $table->index('comment_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('homework_files');
        Schema::dropIfExists('homework_comments');
        Schema::dropIfExists('homework_submissions');
    }
};
