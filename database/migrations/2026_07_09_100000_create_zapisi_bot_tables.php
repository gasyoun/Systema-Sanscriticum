<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H164 "@zapisi_ORSbot integration" (Telegram Track C, D7-D11):
 *   Uprava/docs/DECISIONS_telegram_harvester.md (D7-D11 rulings).
 *
 * Three small, independent tables — deliberately NOT wired into
 * lessons/Group/Tariff (those model course curriculum, not ad-hoc class
 * bookings, per D10):
 *
 *  - zapisi_bot_settings: singleton (mirrors MarketingSetting's one-row
 *    pattern) holding the bot's own encrypted token + webhook secret (D8).
 *  - zapisi_roster_members: thin local cache of the chat's member roster,
 *    synced from the personal-account MadelineProto session (D9). The
 *    authoritative record is the out-of-git corpus store; this table exists
 *    only so the Filament dashboard tab has something to query.
 *  - zapisi_class_schedules: ad-hoc "class starts at" rows for the 1h
 *    reminder job (D10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zapisi_bot_settings', function (Blueprint $table) {
            $table->id();
            // The booking chat this integration targets — a numeric Telegram
            // chat id (peer added to TELEGRAM_HARVEST_PEERS for D7/D9).
            $table->string('chat_id')->nullable();
            $table->string('bot_username')->nullable();
            // Encrypted at rest (Eloquent 'encrypted' cast on the model) —
            // same approach as MarketingSetting.tg_bot_token/tg_webhook_secret.
            $table->text('bot_token')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->timestamps();
        });

        Schema::create('zapisi_roster_members', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->string('telegram_user_id');
            $table->string('username')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->boolean('is_bot')->default(false);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'telegram_user_id']);
        });

        Schema::create('zapisi_class_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id');
            $table->timestamp('starts_at');
            $table->text('message_template');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['starts_at', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zapisi_class_schedules');
        Schema::dropIfExists('zapisi_roster_members');
        Schema::dropIfExists('zapisi_bot_settings');
    }
};
