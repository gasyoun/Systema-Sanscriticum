<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Грант «проверяющий ↔ группа» (H1729).
 *
 * Привязка к users.id, а НЕ к teachers.id — сознательно. Проверяющий это тот,
 * кто заходит в админку (homework_submissions.reviewed_by уже указывает на
 * users.id), и грант обязан жить отдельно от зарплатного контура: со-препод в
 * pivot course_teacher попадает в Course::salaryTermsFor() и начал бы начислять
 * ЗП с выручки чужих курсов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_reviewer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // Может выносить вердикт (принять/вернуть). false = только смотреть и комментировать.
            $table->boolean('can_review')->default(true);
            // Получает оповещения о новых сданных работах.
            $table->boolean('notify')->default(true);
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();

            $table->unique(['group_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_reviewer');
    }
};
