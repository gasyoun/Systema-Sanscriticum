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
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title'); // Заголовок
            $table->string('preview'); // Краткое превью (для закрытого аккордеона)
            $table->text('content'); // Полный текст (с HTML)
            $table->boolean('is_published')->default(true); // Опубликовано ли
            $table->boolean('send_to_email')->default(false); // Нужно ли дублировать на почту
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
