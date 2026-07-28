<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Автооткрытие приёма ДЗ (H1764, волна 1).
 *
 * Отдельного булева `homework_auto_managed` тут НЕТ сознательно:
 * `homework_auto_opened_at IS NOT NULL` и есть признак владения. Урок,
 * открытый преподавателем руками, оставляет колонку NULL — и автозакрытие
 * волны 2 его не увидит. Это правило D10, выраженное схемой, а не веткой в коде.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            if (! Schema::hasColumn('lessons', 'textbook_lesson')) {
                $table->unsignedTinyInteger('textbook_lesson')->nullable()->after('homework_attachments');
            }
            if (! Schema::hasColumn('lessons', 'recording_attached_at')) {
                $table->timestamp('recording_attached_at')->nullable()->after('textbook_lesson');
            }
            if (! Schema::hasColumn('lessons', 'homework_opens_at')) {
                $table->timestamp('homework_opens_at')->nullable()->after('recording_attached_at');
            }
            if (! Schema::hasColumn('lessons', 'homework_auto_opened_at')) {
                $table->timestamp('homework_auto_opened_at')->nullable()->after('homework_opens_at');
            }
            if (! Schema::hasColumn('lessons', 'homework_closed_at')) {
                $table->timestamp('homework_closed_at')->nullable()->after('homework_auto_opened_at');
            }
        });

        // По этой колонке ходит ежечасная выборка «кого пора открывать».
        Schema::table('lessons', function (Blueprint $table) {
            $table->index('homework_opens_at', 'lessons_homework_opens_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex('lessons_homework_opens_at_index');
        });

        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn([
                'textbook_lesson',
                'recording_attached_at',
                'homework_opens_at',
                'homework_auto_opened_at',
                'homework_closed_at',
            ]);
        });
    }
};
