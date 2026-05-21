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
            // Дата фактического завершения участия в курсе. Заполняется только
            // для «терминальных» статусов (Покинул / Исключен / Выпускник).
            // Для Льготника/Рассрочки/Записался — остаётся null.
            $table->date('left_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('course_user', function (Blueprint $table) {
            $table->dropColumn('left_at');
        });
    }
};
