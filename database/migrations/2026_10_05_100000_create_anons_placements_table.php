<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * H6095 — машинный журнал размещений кампаний: anons:ops journal-add/fill/list.
 * Хвост публикации (permalink, время, 24h/72h клики) пишется командой, а не
 * ручным PR по md-документу. PII-правило как у anons_link_clicks: только
 * агрегаты — слаг, UTM-кортеж, счётчики кликов; никаких персональных данных.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('anons_placements', function (Blueprint $table): void {
            $table->id();
            // /ga/ ключ = <кампания>-<канал>-<креатив>; unique = идемпотентность
            // по (campaign, creative) — повторный journal-add обновляет строку.
            $table->string('link', 64)->unique();
            $table->string('campaign', 64)->index();
            $table->string('creative', 64)->index();
            $table->string('channel', 32)->index();
            $table->string('destination', 255)->nullable();
            $table->json('utm')->nullable();
            $table->string('permalink', 500)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->unsignedInteger('clicks_24h')->nullable();
            $table->unsignedInteger('clicks_72h')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('anons_placements');
    }
};
