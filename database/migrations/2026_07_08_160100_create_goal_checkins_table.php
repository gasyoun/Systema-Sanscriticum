<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * История «standup»-снимков цели (H376): каждая запись — прогресс на момент
 * check-in'а (ручного или еженедельного goals:record-checkins). Даёт
 * фактический ЛОГ петли, а не только живой пересчёт на странице.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goal_id')->constrained()->cascadeOnDelete();
            $table->decimal('value_at_checkin', 14, 2);
            $table->string('level', 20);
            $table->string('note')->nullable();
            $table->timestamp('checked_in_at');
            $table->timestamps();

            $table->index(['goal_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_checkins');
    }
};
