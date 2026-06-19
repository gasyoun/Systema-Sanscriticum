<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            // Автор записи: менеджер или null = система (автолог из job/команды).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // note = ручная заметка; email/dm = автолог исходящей коммуникации.
            $table->string('type')->default('note');
            // Канал коммуникации: email|telegram|vk|max (для note — null).
            $table->string('channel')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index(['lead_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
    }
};
