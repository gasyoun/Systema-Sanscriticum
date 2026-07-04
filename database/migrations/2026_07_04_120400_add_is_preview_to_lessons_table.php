<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг публичного бесплатного preview-урока (блок «Пример урока» на лендинге).
 * В отличие от is_free (открыт залогиненным), preview-урок доступен и гостю —
 * единственная точка правды доступа: Lesson::isUnlockedBy() открывает его всем.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->boolean('is_preview')->default(false)->after('is_free');
            $table->index(['course_id', 'is_preview']);
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'is_preview']);
            $table->dropColumn('is_preview');
        });
    }
};
