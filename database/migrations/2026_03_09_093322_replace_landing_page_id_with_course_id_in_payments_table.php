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

        // 2. Отвязываем и удаляем старую колонку лендинга. На MySQL (продакшен, где эта
        // миграция уже применена) dropForeign+dropColumn работают как обычно. На SQLite
        // (тесты/CI) dropForeign безусловно бросает BadMethodCallException
        // (Blueprint::ensureCommandsAreValid() — это НЕ зависит от Doctrine DBAL/native
        // schema operations, вопреки прежнему комментарию здесь), а нативный
        // ALTER TABLE ... DROP COLUMN тоже не спасает: колонка всё ещё упомянута в
        // определении FK самой таблицы, и SQLite отказывается её удалить ("unknown
        // column ... in foreign key definition"). Вместо удаления делаем колонку nullable
        // через Doctrine DBAL (->change(), требует пересоздания таблицы под капотом) —
        // иначе NOT NULL landing_page_id ломает любую вставку в payments, которая его не
        // заполняет (весь код давно пишет course_id, не landing_page_id).
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['landing_page_id']);
            });

            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn('landing_page_id');
            });
        } else {
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('landing_page_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('landing_page_id')->nullable()->constrained('landing_pages')->cascadeOnDelete();
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropForeign(['course_id']);
                $table->dropColumn('course_id');
            });
        }
    }
};
