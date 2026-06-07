<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_user', function (Blueprint $table) {
            // Номер блока курса, С которого студент начал обучение (вошёл в
            // поток). Симметрично left_after_block: тот ограничивает долг
            // сверху, этот — снизу. Блоки ДО joined_at_block в долг не
            // начисляются (студент присоединился к потоку в середине и за
            // прошедшие блоки не отвечает).
            //
            // NULL = не задано вручную → нижняя граница долга вычисляется
            // автоматически как первый реально оплаченный блок студента.
            if (! Schema::hasColumn('course_user', 'joined_at_block')) {
                $table->unsignedSmallInteger('joined_at_block')->nullable()->after('left_after_block');
            }
        });
    }

    public function down(): void
    {
        Schema::table('course_user', function (Blueprint $table) {
            if (Schema::hasColumn('course_user', 'joined_at_block')) {
                $table->dropColumn('joined_at_block');
            }
        });
    }
};
