<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            // Добавляем недостающие колонки, если их нет
            if (!Schema::hasColumn('lessons', 'slug')) {
                $table->string('slug')->nullable()->after('title');
            }
            if (!Schema::hasColumn('lessons', 'block_number')) {
                $table->integer('block_number')->default(1)->after('course_id');
            }
            if (!Schema::hasColumn('lessons', 'youtube_url')) {
                $table->string('youtube_url')->nullable()->after('rutube_url');
            }
            if (!Schema::hasColumn('lessons', 'is_published')) {
                $table->boolean('is_published')->default(true)->after('block_number');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lessons', function (Blueprint $table) {
            $table->dropColumn(['slug', 'block_number', 'youtube_url', 'is_published']);
        });
    }
};