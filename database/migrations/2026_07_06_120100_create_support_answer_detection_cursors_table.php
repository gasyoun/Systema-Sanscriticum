<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Хай-вотер-марка support:suggest-answers на источник (chat_message /
    // telegram_support_message), чтобы не пересканировать всю историю на каждый
    // 15-минутный прогон. Точная копия механики reminder_detection_cursors (H187).
    public function up(): void
    {
        Schema::create('support_answer_detection_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->unique();
            $table->unsignedBigInteger('last_source_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_answer_detection_cursors');
    }
};
