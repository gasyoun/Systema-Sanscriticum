<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Уровень курса для витрины (H323, beginner on-ramp): «с нуля» /
 * «продолжающим» / «продвинутый». Аддитивно, nullable — классификация
 * существующего каталога заполняется владельцем/куратором из админки;
 * непроставленный уровень просто не показывает бейдж и не попадает в фильтр.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('level', 20)->nullable()->after('format')->index();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('level');
        });
    }
};
