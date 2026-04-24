<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lesson_views', function (Blueprint $table) {
            // Обновляется при каждом heartbeat. Позволяет считать реальную активность на уроке.
            $table->dateTime('last_heartbeat_at')->nullable()->after('last_opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('lesson_views', function (Blueprint $table) {
            $table->dropColumn('last_heartbeat_at');
        });
    }
};