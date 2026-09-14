<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_responses', function (Blueprint $table) {
            $table->id();
            $table->string('survey_slug', 64)->index();
            $table->json('answers');
            $table->string('contact')->nullable();
            $table->string('reward_choice', 16)->nullable();
            $table->unsignedBigInteger('reward_user_id')->nullable();
            $table->timestamp('reward_sent_at')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->timestamps();

            $table->foreign('reward_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_responses');
    }
};
