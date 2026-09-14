<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H4457 (MG 09-09): таймкоды занятий — канонический приём из n8n-контура
 * (инструменты/канва_tg_drive_ingest.py → JSON → kanva:ingest-timings).
 * Привязка к живым грамматикам transfer view; дедуп по video_url (nullable:
 * AI-вариант без ссылки дедупится по меткам на стороне команды).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kanva_timings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id')->index();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->string('video_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('timings'); // [{start, end?, label}]
            $table->string('source')->default('n8n'); // n8n:<wf>#<exec>
            $table->string('valid_status')->default('valid'); // valid|gaps|dups
            $table->timestamp('last_ingested_at')->nullable();
            $table->timestamps();

            $table->unique('video_url');
            $table->index(['course_id', 'last_ingested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kanva_timings');
    }
};
