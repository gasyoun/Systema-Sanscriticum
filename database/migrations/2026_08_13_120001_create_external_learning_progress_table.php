<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_learning_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('object_id', 191);
            $table->string('surface', 24);
            $table->string('status', 24);
            $table->unsignedSmallInteger('score')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('first_started_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'object_id'], 'elp_user_provider_object_unique');
            $table->index(['user_id', 'surface', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_learning_progress');
    }
};
