<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('lesson_user', function (Blueprint $table) {
            $table->id();
            
            // Связь со студентом
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            
            // Связь с уроком
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            
            // Дополнительные поля
            $table->text('notes')->nullable(); // Личные заметки
            $table->boolean('is_completed')->default(true); // Пройден ли урок
            
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lesson_user');
    }
};