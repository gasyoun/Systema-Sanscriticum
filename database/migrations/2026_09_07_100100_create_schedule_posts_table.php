<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4328: память свипа полного поста расписания. Один ряд на группу:
 * хэш последнего отправленного текста + момент отправки. Свип шлёт только
 * при смене хэша — TelegramSendGuard (TTL 86400) на вторые сутки уже не
 * спасает от повторной отправки неизменённого текста.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->string('text_hash', 64);
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique('group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_posts');
    }
};
