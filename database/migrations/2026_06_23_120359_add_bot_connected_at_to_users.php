<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Когда студент привязал бота (нажал Start с токеном в TG / перешёл по ref в VK).
    // NULL = не привязан / привязка была до появления трекинга. Для аналитики
    // «кто и когда подключил бота» и сортировки в админке.
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'telegram_connected_at')) {
                $table->timestamp('telegram_connected_at')->nullable()->after('telegram_auth_token');
            }
            if (! Schema::hasColumn('users', 'vk_connected_at')) {
                $table->timestamp('vk_connected_at')->nullable()->after('vk_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['telegram_connected_at', 'vk_connected_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
