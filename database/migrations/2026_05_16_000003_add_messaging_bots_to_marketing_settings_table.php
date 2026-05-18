<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->enum('magnet_delivery_mode', ['redirect', 'page'])->default('redirect');

            // Telegram bot для lead-magnet (отдельный от user-уведомлений)
            $table->string('tg_bot_username', 100)->nullable();
            $table->text('tg_bot_token')->nullable();
            $table->string('tg_webhook_secret', 64)->nullable();

            // VK community для lead-magnet
            $table->string('vk_group_screen_name', 100)->nullable();
            $table->string('vk_group_id', 20)->nullable();
            $table->text('vk_access_token')->nullable();
            $table->string('vk_callback_secret', 64)->nullable();
            $table->string('vk_confirmation_code', 64)->nullable();

            // Max bot
            $table->string('max_bot_username', 100)->nullable();
            $table->text('max_bot_token')->nullable();
            $table->string('max_webhook_secret', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'magnet_delivery_mode',
                'tg_bot_username',
                'tg_bot_token',
                'tg_webhook_secret',
                'vk_group_screen_name',
                'vk_group_id',
                'vk_access_token',
                'vk_callback_secret',
                'vk_confirmation_code',
                'max_bot_username',
                'max_bot_token',
                'max_webhook_secret',
            ]);
        });
    }
};
