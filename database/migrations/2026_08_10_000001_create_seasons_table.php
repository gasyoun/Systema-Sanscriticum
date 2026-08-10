<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('title');                        // «Сезон 1: Осень 2026»
            $table->string('slug', 40)->unique();           // autumn-2026
            $table->dateTime('started_at')->nullable();
            $table->dateTime('ended_at')->nullable();
            $table->boolean('is_active')->default(false);
            // JSON: ['verb-roots','ligatures',...] — активные /lila паки в сезоне
            $table->json('enabled_packs')->nullable();
            // JSON: [{position:1,type:'prana',amount:5000},{...}]
            $table->json('rewards_config')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};
