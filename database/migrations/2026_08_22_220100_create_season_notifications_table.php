<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * H3297 — sent-marker рассылки о старте сезона. Уникальный индекс
     * (season_id, user_id, channel) делает отправку идемпотентной: повторный
     * прогон cron (T-24h, 30-08 21:00 UTC) не задвоит письма/сообщения.
     */
    public function up(): void
    {
        Schema::create('season_notifications', function (Blueprint $table) {
            $table->id();
            // Без FK на seasons: рассылка стреляет T-24h ДО season:open, строки
            // сезона в этот момент может ещё не быть — маркер привязан к id,
            // а не к строке.
            $table->unsignedBigInteger('season_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 20); // email | telegram
            $table->dateTime('sent_at');
            $table->timestamps();

            $table->unique(['season_id', 'user_id', 'channel']);
            $table->index('season_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_notifications');
    }
};
