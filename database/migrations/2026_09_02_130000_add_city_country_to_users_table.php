<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3909 (MG 02-09-2026): спрашиваем страну/город у каждого ученика и заносим
 * их в карточку. Раньше «из-за рубежа» определялось только по каналу оплаты
 * и городу, вписанному куратором в поле name («Кессель Анастасия, Гренхен,
 * Швейцария») — теперь это явные поля. Переименование по правилу
 * «Имя, Город, Страна» делает куратор руками.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('city')->nullable()->after('phone');
            $table->string('country')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['city', 'country']);
        });
    }
};
