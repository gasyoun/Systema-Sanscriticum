<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grammar_exercises', function (Blueprint $table) {
            $table->unsignedInteger('version_n')->default(1)->after('content_hash');
            $table->string('previous_content_hash', 64)->nullable()->after('version_n');
            $table->string('approval_record', 191)->nullable()->after('previous_content_hash');
            $table->boolean('in_review_sample')->default(false)->after('approval_record');
            $table->timestamp('killed_at')->nullable()->after('in_review_sample');
            $table->json('validation_errors')->nullable()->after('killed_at');
        });

        Schema::create('grammar_exercise_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grammar_exercise_id')->constrained('grammar_exercises')->cascadeOnDelete();
            $table->string('exercise_id', 160)->index();
            $table->unsignedInteger('version_n');
            $table->string('content_hash', 64);
            $table->string('status', 32);
            $table->json('payload');
            $table->timestamp('recorded_at');
            $table->timestamps();
            $table->unique(['grammar_exercise_id', 'version_n'], 'gl_ex_ver_unique');
        });

        Schema::create('grammar_exercise_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('exercise_id', 160)->index();
            $table->string('content_hash', 64);
            $table->string('decision', 32);
            $table->string('note', 500)->nullable();
            $table->string('reviewer', 191)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('grammar_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic_id', 160)->index();
            $table->string('exercise_id', 160)->index();
            $table->string('content_hash', 64);
            $table->string('given', 500);
            $table->boolean('correct');
            $table->timestamp('attempted_at');
            $table->timestamps();
            $table->index(['user_id', 'topic_id']);
        });

        Schema::create('grammar_mastery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic_id', 160);
            $table->string('state', 32);
            $table->unsignedInteger('correct_streak')->default(0);
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('correct_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'topic_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grammar_mastery');
        Schema::dropIfExists('grammar_attempts');
        Schema::dropIfExists('grammar_exercise_reviews');
        Schema::dropIfExists('grammar_exercise_versions');
        Schema::table('grammar_exercises', function (Blueprint $table) {
            $table->dropColumn([
                'version_n',
                'previous_content_hash',
                'approval_record',
                'in_review_sample',
                'killed_at',
                'validation_errors',
            ]);
        });
    }
};
