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
    Schema::create('certificates', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Кому выдали
        $table->foreignId('course_id')->constrained()->cascadeOnDelete(); // За какой курс
        $table->string('number')->unique(); // Уникальный номер (например, SANSK-123)
        $table->date('issued_at'); // Дата выдачи
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certificates');
    }
};
