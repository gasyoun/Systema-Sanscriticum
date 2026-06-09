<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Какие пошаговые письма (по событиям бота из n8n) уже отправлены лиду.
    // UNIQUE(lead_id, step) → идемпотентность: ретрай n8n не создаст дубль.
    public function up(): void
    {
        Schema::create('lead_step_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->string('step', 64);
            $table->dateTime('sent_at');
            $table->timestamps();

            $table->unique(['lead_id', 'step']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_step_emails');
    }
};
