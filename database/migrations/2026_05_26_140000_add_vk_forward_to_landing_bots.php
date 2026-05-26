<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_bots', function (Blueprint $table) {
            // VK-сообщество одно на все лендинги (креды — в MarketingSetting).
            // Per-landing у VK только маршрут в n8n: какой сценарий анкеты/прогрева
            // запускать для лидов этого лендинга.
            $table->boolean('vk_is_active')->default(false)->after('n8n_forward_url');
            $table->string('vk_n8n_forward_url', 500)->nullable()->after('vk_is_active');
        });
    }

    public function down(): void
    {
        Schema::table('landing_bots', function (Blueprint $table) {
            $table->dropColumn(['vk_is_active', 'vk_n8n_forward_url']);
        });
    }
};
