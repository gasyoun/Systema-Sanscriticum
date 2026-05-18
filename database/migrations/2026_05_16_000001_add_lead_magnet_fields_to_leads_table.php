<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('magnet_token', 16)->nullable()->unique()->after('social');
            $table->enum('magnet_channel', ['telegram', 'vk', 'max'])->nullable()->after('magnet_token');
            $table->timestamp('magnet_delivered_at')->nullable()->after('magnet_channel');
            $table->bigInteger('telegram_chat_id')->nullable()->after('magnet_delivered_at');
            $table->bigInteger('vk_user_id')->nullable()->after('telegram_chat_id');
            $table->string('max_user_id', 255)->nullable()->after('vk_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn([
                'magnet_token',
                'magnet_channel',
                'magnet_delivered_at',
                'telegram_chat_id',
                'vk_user_id',
                'max_user_id',
            ]);
        });
    }
};
