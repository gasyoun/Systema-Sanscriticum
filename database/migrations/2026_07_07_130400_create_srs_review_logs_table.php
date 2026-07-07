<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS (Wave 1, H211) — журнал повторений (append-only). Каждая оценка карточки
 * пишет строку: grade 1–4, потраченное время (elapsed_ms), назначенный интервал
 * (scheduled_days) и снимок FSRS-состояния после (stability/difficulty). Служит
 * сырьём для будущей пере-оптимизации весов FSRS (Wave 5). Не редактируется.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srs_review_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained('srs_cards')->cascadeOnDelete();
            $table->foreignId('deck_id')->nullable()->constrained('srs_decks')->nullOnDelete();
            $table->unsignedTinyInteger('rating'); // 1 Again, 2 Hard, 3 Good, 4 Easy
            $table->unsignedTinyInteger('state'); // фаза FSRS после оценки
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->integer('scheduled_days')->default(0);
            $table->double('stability_after')->nullable();
            $table->double('difficulty_after')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->index(['user_id', 'reviewed_at']);
            $table->index('card_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srs_review_logs');
    }
};
