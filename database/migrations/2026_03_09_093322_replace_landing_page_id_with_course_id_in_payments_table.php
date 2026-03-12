<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // 1. Добавляем новую правильную колонку для курса
            $table->foreignId('course_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('payments', function (Blueprint $table) {
            // 2. Аккуратно отвязываем и удаляем старую колонку лендинга
            $table->dropForeign(['landing_page_id']);
            $table->dropColumn('landing_page_id');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('landing_page_id')->nullable()->constrained('landing_pages')->cascadeOnDelete();
            $table->dropForeign(['course_id']);
            $table->dropColumn('course_id');
        });
    }
};