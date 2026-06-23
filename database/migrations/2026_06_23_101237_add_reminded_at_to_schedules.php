<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Когда по занятию ушло напоминание студентам (classes:remind-upcoming).
    // NULL → ещё не напоминали. Гарантирует «не более одного пуша на занятие»;
    // при переносе занятия (смена start) сбрасывается в null моделью Schedule.
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (! Schema::hasColumn('schedules', 'reminded_at')) {
                $table->timestamp('reminded_at')->nullable()->after('end');
            }
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            if (Schema::hasColumn('schedules', 'reminded_at')) {
                $table->dropColumn('reminded_at');
            }
        });
    }
};
