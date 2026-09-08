<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Опубликованный конспект лекций (H4325, «Мои материалы» 3/3) — пишется
 * ТОЛЬКО через CourseMaterialSubmissionService::publish(), никогда напрямую
 * с формы препода (анти-цель: заявка не публикует себя сама).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->longText('teacher_notes')->nullable()->after('video_announce_url');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('teacher_notes');
        });
    }
};
