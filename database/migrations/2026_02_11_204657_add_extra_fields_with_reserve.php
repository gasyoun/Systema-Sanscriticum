<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Безопасно добавляем поля ученику (Users)
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'phone')) $table->string('phone')->nullable();
            if (!Schema::hasColumn('users', 'telegram_id')) $table->string('telegram_id')->nullable();
            if (!Schema::hasColumn('users', 'source')) $table->string('source')->nullable();
            if (!Schema::hasColumn('users', 'utm_campaign')) $table->string('utm_campaign')->nullable();
            if (!Schema::hasColumn('users', 'social_links')) $table->json('social_links')->nullable();
        });

        // 2. Безопасно добавляем поля урокам (Lessons)
        Schema::table('lessons', function (Blueprint $table) {
            if (!Schema::hasColumn('lessons', 'course_id')) $table->string('course_id')->nullable();
            if (!Schema::hasColumn('lessons', 'duration_minutes')) $table->integer('duration_minutes')->nullable();
            if (!Schema::hasColumn('lessons', 'is_free')) $table->boolean('is_free')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'telegram_id', 'source', 'utm_campaign', 'social_links']);
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['course_id', 'duration_minutes', 'is_free']);
        });
    }
};

