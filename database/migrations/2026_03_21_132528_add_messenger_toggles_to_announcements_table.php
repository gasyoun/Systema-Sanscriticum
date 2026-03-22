<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Добавляем галочку для Telegram (по умолчанию выключена)
            $table->boolean('send_to_telegram')->default(false)->after('send_to_email');
            
            // Добавляем галочку для VK (по умолчанию выключена)
            $table->boolean('send_to_vk')->default(false)->after('send_to_telegram');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            // Если будем откатывать миграцию — удаляем эти колонки
            $table->dropColumn(['send_to_telegram', 'send_to_vk']);
        });
    }
};