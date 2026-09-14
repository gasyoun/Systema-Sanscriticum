<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3648 — typed recording class the entitlement checker can read.
 *
 * Default `course_lesson` on every existing row: Club membership does not
 * infer video access from «previously purchased course» (26-08 ruling).
 * Tag club streams / efirs explicitly (`club_stream` / `club_efir`).
 * Deploy-inert: the predicate change is behind MEMBERSHIP_CLUB_STREAMS_ONLY
 * (default false). Missing column is treated as course_lesson.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->string('recording_kind', 32)->default('course_lesson')->after('is_preview');
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn('recording_kind');
        });
    }
};
