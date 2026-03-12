<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Добавляем два поля: числовые, могут быть пустыми (nullable)
            $table->integer('lessons_count')->nullable()->after('description');
            $table->integer('hours_count')->nullable()->after('lessons_count');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['lessons_count', 'hours_count']);
        });
    }
};