<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H5066: порог возобновления занятий на курсе — сколько заявок «revive» нужно
 * собрать, чтобы группа собралась. NULL = порог не задан, решает куратор
 * (витрина «спрос есть» в Filament показывает счётчик и без порога).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->unsignedInteger('revive_threshold')->nullable()->after('never_repeat');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table): void {
            $table->dropColumn('revive_threshold');
        });
    }
};
