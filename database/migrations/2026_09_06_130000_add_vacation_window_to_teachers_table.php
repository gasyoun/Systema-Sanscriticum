<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4253: окно каникул/отпуска преподавателя.
 *
 * Оба поля nullable: заполнено только «from» — отпуск без известной даты
 * выхода (в фиде помечается «дата выхода из каникул уточняется», как у
 * группового флага H3790); оба пусты — преподаватель не в отпуске.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teachers', function (Blueprint $table): void {
            $table->date('on_vacation_from')->nullable()->after('payout_currency');
            $table->date('on_vacation_until')->nullable()->after('on_vacation_from');
        });
    }

    public function down(): void
    {
        Schema::table('teachers', function (Blueprint $table): void {
            $table->dropColumn(['on_vacation_from', 'on_vacation_until']);
        });
    }
};
