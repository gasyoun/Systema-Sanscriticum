<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // High-water mark per source so promises:detect-deferrals does not rescan
    // full ChatMessage / TelegramSupportMessage history every 15 minutes (H2156).
    // Separate table from reminder_detection_cursors — same shape, independent cursors.
    public function up(): void
    {
        Schema::create('promise_suggestion_detection_cursors', function (Blueprint $table) {
            $table->id();
            $table->string('source_type')->unique();
            $table->unsignedBigInteger('last_source_id')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promise_suggestion_detection_cursors');
    }
};
