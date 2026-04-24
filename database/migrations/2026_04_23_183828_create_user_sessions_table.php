<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            // Laravel session id для связи с таблицей sessions (если используется session driver=database)
            $table->string('session_id', 64)->nullable()->unique();

            // ИЗМЕНЕНИЕ: timestamp → datetime
            // Причина: в некоторых MySQL конфигах TIMESTAMP NOT NULL требует обязательный DEFAULT.
            // DATETIME лишён этой проблемы и семантически корректнее для бизнес-событий.
            $table->dateTime('started_at');
            $table->dateTime('last_heartbeat_at');
            $table->dateTime('ended_at')->nullable();

            $table->unsignedInteger('duration_seconds')->default(0);

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->enum('device_type', ['desktop', 'mobile', 'tablet', 'bot', 'unknown'])
                ->default('unknown');
            $table->string('browser', 50)->nullable();
            $table->string('os', 50)->nullable();

            $table->unsignedInteger('pages_viewed')->default(0);
            $table->unsignedInteger('lessons_viewed')->default(0);

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['user_id', 'started_at']);
            $table->index(['is_active', 'last_heartbeat_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};