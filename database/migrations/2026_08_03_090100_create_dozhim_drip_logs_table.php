<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Идемпотентная отметка «этот шаг дрипа этой сделке уже уходил»
     * (H2059, IMPLEMENTATION H-B п.5, флаг dozhim_drip). Без неё
     * ежедневный прогон dozhim:drip слал бы один и тот же шаг повторно.
     */
    public function up(): void
    {
        Schema::create('dozhim_drip_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained('deals')->cascadeOnDelete();
            $table->string('step', 8); // day0 | day3 | day7 — см. MessageTemplate::dozhimSteps()
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['deal_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dozhim_drip_logs');
    }
};
