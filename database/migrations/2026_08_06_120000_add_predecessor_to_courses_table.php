<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * H2333: continuation streams point at the course shell that holds lessons 1…N−1.
 * Student cabinet shows a banner so students find “where is lesson 1?” without a curator.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->foreignId('predecessor_course_id')
                ->nullable()
                ->after('level')
                ->constrained('courses')
                ->nullOnDelete();
            $table->unsignedSmallInteger('continues_from_lesson')
                ->nullable()
                ->after('predecessor_course_id');
        });

        // Prod Hindi: gr.5 вт (continuation from #13) → gr.3 пт (archive from #1).
        // IDs are stable on samskrte.ru; no-op when fixtures lack those rows.
        if (
            DB::table('courses')->where('id', 366)->exists()
            && DB::table('courses')->where('id', 356)->exists()
        ) {
            DB::table('courses')->where('id', 366)->update([
                'predecessor_course_id' => 356,
                'continues_from_lesson' => 13,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('predecessor_course_id');
            $table->dropColumn('continues_from_lesson');
        });
    }
};
