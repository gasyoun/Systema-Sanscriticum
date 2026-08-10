<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Библиотека курса, фаза 1 — реестр ссылок на литературу.
 *
 * Файлы НЕ забираем: растёт только эта таблица, storage/app не растёт вовсе
 * (а он целиком попадает в недельный бэкап). Фаза 2 при желании добавит
 * disk/path/size/sha256 — схема к этому готова, но сейчас их нет намеренно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_materials', function (Blueprint $table) {
            $table->id();

            $table->foreignId('course_id')->constrained()->cascadeOnDelete();

            // null = материал всего курса (общая библиография), а не урока.
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->string('author')->nullable();
            $table->string('year', 32)->nullable();

            // Ссылка как её дал преподаватель — храним дословно, чтобы можно
            // было пересчитать производные поля, если правила нормализации
            // изменятся.
            $table->text('source_url');

            // Нормализованная прямая ссылка (Dropbox dl=1, Google Drive uc?...).
            $table->text('download_url')->nullable();

            $table->string('host', 190)->nullable();
            $table->string('kind', 20)->default('page');
            $table->text('note')->nullable();
            $table->integer('sort_order')->default(0);

            // По умолчанию скрыт: куратор сперва проверяет строку, потом
            // публикует. Внешние ссылки гниют — публикация ручная осознанно.
            $table->boolean('is_visible')->default(false);

            $table->foreignId('added_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['course_id', 'is_visible', 'sort_order'], 'course_materials_shelf_idx');
            $table->index('lesson_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_materials');
    }
};
