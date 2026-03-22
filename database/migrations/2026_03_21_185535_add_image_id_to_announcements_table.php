<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Проверяем, есть ли таблица, чтобы ничего не упало
        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table) {
                // Добавляем колонку только если её там нет
                if (!Schema::hasColumn('announcements', 'image_id')) {
                    $table->foreignId('image_id')->nullable()->constrained('media')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('announcements')) {
            Schema::table('announcements', function (Blueprint $table) {
                if (Schema::hasColumn('announcements', 'image_id')) {
                    $table->dropForeign(['image_id']);
                    $table->dropColumn('image_id');
                }
            });
        }
    }
};