<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // Порядок уроков ведётся в пределах (course_id, group_id), а не всего курса.
            // Индекс ускоряет выборку и перенумерацию sort_order внутри одного потока.
            $table->index(['course_id', 'group_id', 'sort_order'], 'lessons_course_group_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex('lessons_course_group_sort_idx');
        });
    }
};
