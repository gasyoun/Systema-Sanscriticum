<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Telegram Track C (H164, Uprava/docs/DECISIONS_telegram_harvester.md D10):
 * ad-hoc "записи" (booking) class schedule for @zapisi_ORSbot's chat —
 * deliberately independent of lessons/Group/Tariff (curriculum model), which
 * only carries a date, not a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zapisi_class_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('chat_id')->index();
            $table->dateTime('starts_at')->index();
            $table->text('message_template');
            $table->timestamp('sent_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zapisi_class_schedules');
    }
};
