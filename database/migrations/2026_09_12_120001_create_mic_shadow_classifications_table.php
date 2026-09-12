<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4608 — MIC shadow classify-all-inbound: null-телеметрия + near-miss.
 *
 * Одна строка на (входящее сообщение × плоскость MIC). Никогда не содержит
 * текст сообщения — только sha256 нормализованного текста (PII-фенс);
 * сырой текст остаётся исключительно в маскированных корпусах MIC.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mic_shadow_classifications', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20); // telegram | web
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->unsignedBigInteger('message_id')->nullable();
            $table->char('text_hash', 64); // sha256(normalized text), never the text
            $table->string('plane', 20); // topic | objection | intent | meta
            $table->string('category')->nullable(); // null = uncategorized on this plane
            $table->string('reason')->nullable(); // 'keyword:<pattern>' for winners
            $table->json('near_miss')->nullable(); // [{category, reason}] top-2 (G8)
            $table->timestamp('classified_at')->nullable();
            $table->timestamps();

            // Telegram sync репроцессит те же сообщения (updateOrCreate) —
            // шадоу-запись идемпотентна по (канал, сообщение, плоскость).
            $table->unique(['channel', 'message_id', 'plane']);
            $table->index(['plane', 'category']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_shadow_classifications');
    }
};
