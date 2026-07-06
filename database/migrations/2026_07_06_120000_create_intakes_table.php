<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // «Набор» — плановая волна набора студентов (Июль 2026, Сентябрь 2026,
        // осень 2026, на 2027). Существует ДО формирования групп: держит уже
        // известные номера будущих групп (planned_group_numbers) и после
        // формирования порождает реальные groups (groups.intake_id).
        Schema::create('intakes', function (Blueprint $table) {
            $table->id();
            // Отображаемая метка как в таблице набора: «Июль 2026», «осень 2026».
            $table->string('label');
            // Сезон/месяц словом для группировки: «Июль», «Сентябрь», «осень».
            $table->string('season')->nullable();
            $table->smallInteger('target_year');
            // 1–12; null для нечётких («осень», «на 2027»).
            $table->unsignedTinyInteger('target_month')->nullable();
            // Известные номера будущих групп до их создания: ["61","62"].
            $table->json('planned_group_numbers')->nullable();
            // planning → forming → open → closed.
            $table->string('status')->default('planning');
            // Ориентировочная дата старта набора (для сортировки/оповещений).
            $table->date('opens_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['status', 'target_year', 'target_month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intakes');
    }
};
