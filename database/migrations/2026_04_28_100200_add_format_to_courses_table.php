<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // 'live' — идёт сейчас, 'recorded' — доступен в записи
            $table->string('format', 20)->default('recorded')->after('is_elective');
            $table->index('format');
        });

        // Бэкфилл: все существующие курсы по умолчанию идут как 'recorded',
        // продакт сам переключит в админке тех, что live
        DB::table('courses')->update(['format' => 'recorded']);
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropIndex(['format']);
            $table->dropColumn('format');
        });
    }
};