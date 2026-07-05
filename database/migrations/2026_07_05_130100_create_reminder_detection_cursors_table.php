<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Хай-вотер-марка на источник (chat_message / telegram_support_message),
    // чтобы reminders:detect-requests не пересканировал всю историю на каждый прогон.
    public function up(): void
    {
        Schema::create('reminder_detection_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->unique();
            $table->unsignedBigInteger('last_source_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_detection_cursors');
    }
};
