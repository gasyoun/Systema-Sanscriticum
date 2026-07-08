<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Цель делегированного лида на период: метрика + целевое/стартовое значение +
 * окно (H376, goal-loop счётчик прогресса — младший брат finance:kpi-digest,
 * но для целей, а не финансовых фаз). status='active' пока не закрыт check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            // Свободный текст метрики ('MRR', 'новых учеников', 'NPS') — цели
            // разнородны, единого справочника метрик пока нет.
            $table->string('target_metric');
            $table->decimal('starting_value', 14, 2)->default(0);
            $table->decimal('target_value', 14, 2);
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['status', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
