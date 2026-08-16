<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H2886 — объём клубной полки на уровне КУРСА.
 *
 * До этого `club_included` был булевым: курс либо целиком в клубе, либо нет,
 * потому что ClubEntitlement подмешивал жёстко зашитый ключ `full`. На разборе
 * пусковых шлюзов 28-08 (H2865) это уперлось в реальный запрос: в клуб хотели
 * положить НАЧАЛЬНЫЙ БЛОК Бюлера и БЛОК 1 логики, а не курсы целиком — иначе
 * подписка за ₽1 500/мес отдавала бы 31 урок Бюлера (блоки по ₽8 000) и весь
 * курс логики (₽16 500).
 *
 * Механика для этого в системе уже была: уроки открываются ключами
 * `full` / `block_N` / `block_N_hH` (Lesson::unlockingKeys), и 1 085 боевых
 * тарифов уже пользуются формой `block_N`. Не хватало только места, где сказать,
 * КАКОЙ из этих ключей даёт клуб для конкретного курса.
 *
 * NULL = `full` — прежнее поведение. Миграция deploy-инертна: пока
 * `CLUB_MEMBERSHIP` выключен, клубного права не существует вовсе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('club_access_key', 32)->nullable()->after('club_included');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('club_access_key');
        });
    }
};
