<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('block_number');
            $table->index(['course_id', 'sort_order']);
        });

        // Бэкфилл: сохраняем сегодняшний порядок кабинета (created_at ASC) как ручной,
        // нумеруя уроки внутри каждого курса начиная с 1.
        $courseIds = DB::table('lessons')->distinct()->pluck('course_id');

        foreach ($courseIds as $courseId) {
            $lessons = DB::table('lessons')
                ->where('course_id', $courseId)
                ->orderBy('created_at')
                ->orderBy('id')
                ->pluck('id');

            $position = 1;
            foreach ($lessons as $lessonId) {
                DB::table('lessons')
                    ->where('id', $lessonId)
                    ->update(['sort_order' => $position++]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropIndex(['course_id', 'sort_order']);
            $table->dropColumn('sort_order');
        });
    }
};
