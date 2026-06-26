<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained()->cascadeOnDelete();
            // Гость без сопоставленного аккаунта — user_id NULL (имя/почта всё равно есть).
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Идемпотентность: одна строка на сессию участия (Zoom participant_uuid).
            $table->string('zoom_participant_uuid')->index();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();

            $table->unique(['schedule_id', 'zoom_participant_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_attendances');
    }
};
