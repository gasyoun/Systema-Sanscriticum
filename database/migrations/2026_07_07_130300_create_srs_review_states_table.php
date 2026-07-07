<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS (Wave 1, H211) — состояние FSRS для пары «пользователь × карточка».
 * Одна строка на юзера и карточку (unique). Хранит ядро FSRS-параметров
 * (stability/difficulty), фазу (`state`: 1 Learning / 2 Review / 3 Relearning),
 * шаг обучения (`step`, nullable в Review), срок (`due`) и счётчики reps/lapses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srs_review_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('srs_cards')->cascadeOnDelete();
            $table->double('stability')->nullable();
            $table->double('difficulty')->nullable();
            $table->timestamp('due')->nullable();
            $table->unsignedInteger('reps')->default(0);
            $table->unsignedInteger('lapses')->default(0);
            $table->unsignedTinyInteger('state')->default(1); // 1 Learning, 2 Review, 3 Relearning
            $table->unsignedSmallInteger('step')->nullable();
            $table->timestamp('last_review')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'card_id']);
            $table->index(['user_id', 'due']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srs_review_states');
    }
};
