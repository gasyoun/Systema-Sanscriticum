<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // H3215 — попытки внутреннего теста кабинета (куратор / студент).
    public function up(): void
    {
        Schema::create('cabinet_mastery_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('audience', 32);
            $table->unsignedTinyInteger('score');
            $table->unsignedTinyInteger('total');
            $table->boolean('passed')->default(false);
            $table->json('answers');
            $table->timestamps();

            $table->index(['user_id', 'audience', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cabinet_mastery_attempts');
    }
};
