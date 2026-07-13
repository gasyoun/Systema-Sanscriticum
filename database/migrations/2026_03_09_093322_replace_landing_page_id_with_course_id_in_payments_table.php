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

        // 2. Аккуратно отвязываем и удаляем старую колонку лендинга.
        // Сначала снимаем FK, затем колонку — в отдельных Schema::table, чтобы на
        // SQLite перестройка таблицы (снятие FK) и нативный DROP COLUMN не пересеклись.
        // Начиная с Laravel 11 Doctrine DBAL убран: SQLite использует нативный
        // ALTER TABLE ... DROP COLUMN, который отказывается удалять колонку, всё ещё
        // упомянутую в определении FK. dropForeign на SQLite в L11+ работает через
        // перестройку таблицы, поэтому драйвер-специфичная ветка больше не нужна.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['landing_page_id']);
        });

        Schema::table('payments', function (Blueprint $table) {
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
