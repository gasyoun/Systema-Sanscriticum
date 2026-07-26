<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GC-C1 (H1641) — журнал переходов сделки по стадиям.
 *
 * СОЗНАТЕЛЬНО БЕЗ FK на deals (unsignedBigInteger + index) — тот же приём,
 * что у lead_audits и promise_events: append-only журнал должен пережить
 * удаление родителя. Спека §5 п.5 предписывает это явно.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_transitions', function (Blueprint $table) {
            $table->id();
            // Без constrained(): журнал переживает удаление сделки.
            $table->unsignedBigInteger('deal_id')->index();
            $table->unsignedBigInteger('from_stage_id')->nullable();
            $table->unsignedBigInteger('to_stage_id');
            // Кто двинул: менеджер с доски либо null — «Система» (мост от оплаты).
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_transitions');
    }
};
