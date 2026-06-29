<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Дата, на которую взят курс PayPal (exchange_rate). Курс подтягивается с
 * exchangerate.host на эту дату; куратор может выбрать любой день и поправить
 * курс вручную. Справочно — рублёвая сумма остаётся источником правды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->date('rate_date')->nullable()->after('exchange_rate');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_payouts', function (Blueprint $table) {
            $table->dropColumn('rate_date');
        });
    }
};
