<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SRS (Wave 1, H211) — колоды. Владелец `user_id` NULL = системная колода
 * (сид из словаря). `course_id`/`lesson_id` — привязка к учебному контенту
 * (заполняется во Wave 2). Видимость: system / public / private.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('srs_decks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lesson_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('note_type_id')->constrained('srs_note_types')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->enum('language', ['sa', 'hi'])->default('sa');
            $table->enum('visibility', ['system', 'public', 'private'])->default('private');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['visibility', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('srs_decks');
    }
};
