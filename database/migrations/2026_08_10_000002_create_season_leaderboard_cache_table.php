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
        Schema::create('season_leaderboard_cache', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('baseline_lifetime_prana')->default(0); // snapshot при season:open
            $table->unsignedInteger('prana_earned')->default(0); // = current lifetime − baseline (P2P-immune)
            $table->unsignedInteger('rank_position')->default(0);
            $table->dateTime('computed_at')->useCurrent();

            $table->unique(['season_id', 'user_id']);
            $table->index(['season_id', 'rank_position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_leaderboard_cache');
    }
};
