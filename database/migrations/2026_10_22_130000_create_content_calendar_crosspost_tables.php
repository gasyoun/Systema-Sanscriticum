<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 22-10-2026: кросспостинг календаря контента в 3 TG-канала и 3 VK-сообщества.
 * slot.destinations — выбранные площадки (null = все включённые в
 * config/content_calendar.php); content_calendar_publications — статус по
 * каждой площадке, чтобы повторять только неудавшиеся; vk_user_tokens —
 * часовой VK ID токен с refresh_token, который обновляет vk:token-refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->json('destinations')->nullable()->after('media_path');
        });

        Schema::create('content_calendar_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_calendar_slot_id')->constrained('content_calendar_slots')->cascadeOnDelete();
            $table->string('destination');
            $table->string('status')->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('external_id')->nullable();
            $table->string('permalink')->nullable();
            $table->text('error')->nullable();
            $table->text('warning')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['content_calendar_slot_id', 'destination'], 'ccp_slot_destination_unique');
        });

        Schema::create('vk_user_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->string('device_id')->nullable();
            $table->string('scope')->nullable();
            $table->unsignedBigInteger('vk_user_id')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('refreshed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vk_user_tokens');
        Schema::dropIfExists('content_calendar_publications');
        Schema::table('content_calendar_slots', function (Blueprint $table) {
            $table->dropColumn('destinations');
        });
    }
};
