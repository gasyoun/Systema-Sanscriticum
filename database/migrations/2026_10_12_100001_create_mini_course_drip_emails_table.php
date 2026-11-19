<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Отметки «письмо об открытии урока мини-курса отправлено» — чтобы дрип
 * не дублировал письма при повторных прогонах команды.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mini_course_drip_emails', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'lesson_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mini_course_drip_emails');
    }
};
