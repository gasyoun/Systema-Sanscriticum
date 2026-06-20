<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Глобально запоминаемый выбор года на странице «Должники».
            // null / пустой массив = все годы (поведение по умолчанию).
            // Будущая авто-рассылка о долгах берёт год отсюда же.
            $table->json('debtors_notify_years')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn('debtors_notify_years');
        });
    }
};
