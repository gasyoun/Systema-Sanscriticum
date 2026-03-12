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
    Schema::table('landing_pages', function (Blueprint $table) {
        // Добавляем поле для даты
        $table->date('webinar_date')->nullable()->after('instructor_name');
        // Добавляем поле для подписи (например "Бесплатный вебинар")
        $table->string('webinar_label')->default('Бесплатный вебинар')->nullable()->after('webinar_date');
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_pages', function (Blueprint $table) {
            //
        });
    }
};
