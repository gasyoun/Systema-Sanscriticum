<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Дедуп: оставляем минимальный id на каждую пару (group_id, user_id),
        //    остальное удаляем. Импорт (ImportAcademyData, insertOrIgnore без
        //    уникального ключа) при повторных прогонах продублировал membership.
        //    Обёртка в производную таблицу нужна для MySQL (нельзя удалять из
        //    таблицы, на которую ссылается подзапрос напрямую); на SQLite тоже ок.
        DB::statement('
            DELETE FROM group_user
            WHERE id NOT IN (
                SELECT keep_id FROM (
                    SELECT MIN(id) AS keep_id
                    FROM group_user
                    GROUP BY group_id, user_id
                ) AS keep
            )
        ');

        // 2. Уникальный ключ — чтобы дубли больше не появлялись, а insertOrIgnore
        //    импорта наконец отрабатывал как задумано.
        Schema::table('group_user', function (Blueprint $table) {
            $table->unique(['group_id', 'user_id'], 'group_user_group_id_user_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('group_user', function (Blueprint $table) {
            $table->dropUnique('group_user_group_id_user_id_unique');
        });
    }
};
