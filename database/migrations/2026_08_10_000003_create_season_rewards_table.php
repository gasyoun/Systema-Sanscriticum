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
        Schema::create('season_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained('seasons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            // prana | badge | discount_percent | custom
            $table->string('reward_type', 40);
            $table->unsignedInteger('reward_value')->default(0);
            $table->dateTime('awarded_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['season_id', 'user_id']);
            $table->index(['season_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('season_rewards');
    }
};
