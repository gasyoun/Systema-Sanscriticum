<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Предварительное предупреждение студента о занятии (H2317):
 * «не приду / не уверен / опоздаю / уйду раньше».
 * Одна запись на пару (user, schedule) — повторная смена статуса обновляет строку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_attendance_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32); // absent|uncertain|late|leave_early
            $table->string('source', 32)->default('cabinet'); // cabinet|telegram|vk|telegram_group
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'schedule_id']);
            $table->index(['schedule_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_attendance_notices');
    }
};
