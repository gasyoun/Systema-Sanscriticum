<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Набор курсов» (H162): группа получает статус набора и плановую/фактическую
        // дату старта — раньше это было чисто ручным знанием куратора («группа
        // недонабрана», сказанное в чат студентке в день ожидаемого старта).
        Schema::table('groups', function (Blueprint $table) {
            // forming = ещё набирается, active = идёт обучение, archived = завершена.
            $table->string('status')->default('forming')->after('intake_id');
            // Порог «набрана»; null = размер не проверяем (историческая/неограниченная группа).
            $table->unsignedTinyInteger('min_size')->nullable()->after('status');
            // Ожидаемая дата старта — дефолт для отсчёта «за N дней».
            $table->date('planned_start_date')->nullable()->after('min_size');
            // Ручной override при переносе — приоритет над planned_start_date.
            $table->date('start_date_override')->nullable()->after('planned_start_date');
            // Дедуп рассылки о недоборе, как Schedule.reminded_at.
            $table->timestamp('recruitment_notified_at')->nullable()->after('start_date_override');
        });
    }

    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'min_size',
                'planned_start_date',
                'start_date_override',
                'recruitment_notified_at',
            ]);
        });
    }
};
