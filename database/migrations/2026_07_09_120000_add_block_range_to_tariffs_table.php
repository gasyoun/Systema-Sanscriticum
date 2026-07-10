<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Тариф-бандл на диапазон блоков (H393).
 *
 * До этого доступ к нескольким блокам одним платежом фабриковался на лету в
 * DebtPaymentController (ключ block_{start} + числовой диапазон) — тарифа-
 * диапазона в админке не было вовсе. Теперь bundle-тариф с course_id несёт
 * явный диапазон блоков start_block..end_block: accessKey() отдаёт block_{start},
 * а BlockAccessMaterializer дорисовывает остальные ключи диапазона.
 *
 * Nullable: обычные тарифы (full/block/vip) и мульти-курсовой пакет (bundle без
 * привязки к курсу) поля не заполняют.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->unsignedInteger('start_block')->nullable()->after('block_half');
            $table->unsignedInteger('end_block')->nullable()->after('start_block');
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn(['start_block', 'end_block']);
        });
    }
};
