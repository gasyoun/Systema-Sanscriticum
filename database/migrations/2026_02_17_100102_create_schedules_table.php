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
    Schema::create('schedules', function (Blueprint $table) {
        $table->id();
        $table->string('title');
        $table->text('description')->nullable();
        $table->dateTime('start');
        $table->dateTime('end')->nullable();
        $table->string('color')->default('#3788d8'); // Синий по умолчанию
        
        // Привязка к группе (опционально)
        $table->foreignId('group_id')->nullable()->constrained()->nullOnDelete();
        
        // Привязка к курсу (опционально, если нужно)
        $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();

        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
