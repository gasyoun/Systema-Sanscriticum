<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Лог авто-напоминаний должникам — для анти-спама (дедуп по user+course
    // в окне cadence) и аудита, что и когда уходило.
    public function up(): void
    {
        Schema::create('debt_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('course_id');
            $table->timestamp('sent_at')->index();
            $table->timestamps();

            $table->index(['user_id', 'course_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_reminders');
    }
};
