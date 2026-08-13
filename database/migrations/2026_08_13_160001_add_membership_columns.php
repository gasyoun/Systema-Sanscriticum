<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2644. Две аддитивные колонки, обе NULL/false на всех существующих строках,
 * поэтому миграция deploy-инертна.
 *
 * tariffs.membership_months — срок клубного тарифа. Он же делает ключ доступа
 * платежа самоописывающим (`club_1m`/`club_3m`/`club_12m`, см. Tariff::accessKey),
 * иначе три клубных тарифа неразличимы: у всех непблочных accessKey() = 'full',
 * а payments не хранит tariff_id.
 *
 * courses.club_included — входит ли курс в клубную полку. По умолчанию false
 * у ВСЕХ 128 курсов: клуб не раздаёт каталог сам собой, полку набирает человек
 * (или `membership:club-catalogue`), и это единственная защита от «купил клуб —
 * получил живой поток», которую нельзя обойти опечаткой в цене.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->unsignedSmallInteger('membership_months')->nullable()->after('is_recording');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->boolean('club_included')->default(false)->after('is_completed');
        });
    }

    public function down(): void
    {
        Schema::table('tariffs', function (Blueprint $table) {
            $table->dropColumn('membership_months');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('club_included');
        });
    }
};
