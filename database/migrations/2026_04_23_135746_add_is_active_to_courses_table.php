<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Флаг «курс доступен студентам в ЛК».
            // Отделен от is_visible (витрина магазина).
            $table->boolean('is_active')
                ->default(true)
                ->after('is_visible');

            // Индекс — фильтруем по нему в dashboard() и showCourse()
            $table->index('is_active');
        });

        // Бэкфилл: у всех существующих курсов is_active = true.
        // Это гарантирует, что НИКТО из студентов не потеряет доступ
        // даже если к моменту миграции курс уже был скрыт с витрины.
        DB::table('courses')->update(['is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};