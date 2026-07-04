<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Подписной токен на персональный iCal/webcal-фид расписания студента
 * (Google Calendar Phase 1 — см. docs/GOOGLE_CALENDAR_INTEGRATION_ROADMAP.md).
 * Отзывной: revoked_at не null → фид отдаёт 404.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feed_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_tokens');
    }
};
