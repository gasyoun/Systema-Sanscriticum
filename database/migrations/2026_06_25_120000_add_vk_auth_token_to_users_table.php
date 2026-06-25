<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Одноразовый неугадываемый токен привязки VK (как telegram_auth_token).
            // Заменяет сырой user id в ?ref= — закрывает IDOR-перехват аккаунта.
            if (! Schema::hasColumn('users', 'vk_auth_token')) {
                $table->string('vk_auth_token')->nullable()->after('vk_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'vk_auth_token')) {
                $table->dropColumn('vk_auth_token');
            }
        });
    }
};
