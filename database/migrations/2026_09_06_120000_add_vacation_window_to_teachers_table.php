<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4253: отпуск/каникулы преподавателя (teacher-level), отдельно от
 * group-level is_on_vacation (H3790) — препод может уйти в отпуск, ведя
 * несколько групп сразу, без переключения флага на каждой из них.
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
