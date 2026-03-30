<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Привязка к преподавателю (если препода удалят, у курса это поле станет null)
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            
            // Финансовые настройки курса
            $table->string('salary_type')->nullable(); // percent, fix_per_student, fix_total
            $table->decimal('salary_value', 10, 2)->nullable(); // Сама ставка (например, 30.00 или 5000.00)
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropColumn(['teacher_id', 'salary_type', 'salary_value']);
        });
    }
};