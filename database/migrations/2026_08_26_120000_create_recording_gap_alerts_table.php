<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H3557: дедуп алертов recordings:gap-watch переезжает из Redis-кэша в БД.
 * Автодеплой сбрасывает кэш (~20 деплоев за 25-08-2026), после чего hourly
 * --stale проход отправлял тот же алерт заново. Строка на отпечаток набора
 * пробелов живёт в БД и переживает любые cache:clear.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_gap_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('fingerprint', 64)->unique();
            $table->string('window_label', 64)->default('');
            $table->unsignedInteger('send_count')->default(1);
            $table->timestamp('first_sent_at');
            $table->timestamp('last_sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_gap_alerts');
    }
};
