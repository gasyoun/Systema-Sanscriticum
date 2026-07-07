<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS (Wave 1, H211) — подписка пользователя на колоду + персональные настройки
 * (лимит новых карточек в день, направление показа). Отделено от srs_review_states,
 * т.к. это настройка на колоду, а не состояние отдельной карточки.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srs_deck_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('deck_id')->constrained('srs_decks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('new_per_day')->default(20);
            $table->enum('direction', ['front_back', 'back_front', 'both'])->default('front_back');
            $table->timestamp('subscribed_at')->nullable();
            $table->timestamps();

            $table->unique(['deck_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srs_deck_user');
    }
};
