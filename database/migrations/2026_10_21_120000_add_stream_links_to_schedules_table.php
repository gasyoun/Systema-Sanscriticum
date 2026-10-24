<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Открытые занятия в @samskrte_bot: ссылки на трансляции (Rutube, VK Видео,
 * YouTube) рядом с Zoom. У каждого эфира свои — поэтому на строке расписания.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->json('stream_links')->nullable()->after('card_note');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn('stream_links');
        });
    }
};
