<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B2B-оплата «за группу целиком» (H8245): отметка «платит сам» на членстве
 * ученика в группе. В смешанной группе часть учеников покрывает платёж юрлица
 * (b2b_group), а отмеченные платят блоки сами — групповой платёж не даёт им
 * ни виртуальных ключей доступа, ни снятия из должников.
 *
 * Дефолт false: существующие членства остаются «по группе», поведение прежнее.
 * Читается только под флагом features.b2b_group_self_pay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('group_user', function (Blueprint $table) {
            $table->boolean('b2b_self_pay')->default(false)->after('left_reason');
        });
    }

    public function down(): void
    {
        Schema::table('group_user', function (Blueprint $table) {
            $table->dropColumn('b2b_self_pay');
        });
    }
};
