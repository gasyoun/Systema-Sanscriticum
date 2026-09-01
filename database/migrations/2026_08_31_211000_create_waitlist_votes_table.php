<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Голоса учеников за строки списка ожидания (MG ruling 31-08-2026):
 * только зарегистрированные в кабинете (user_id), один голос с пользователя
 * на элемент (unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_waitlist_item_id')->constrained('course_waitlist_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['course_waitlist_item_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_votes');
    }
};
