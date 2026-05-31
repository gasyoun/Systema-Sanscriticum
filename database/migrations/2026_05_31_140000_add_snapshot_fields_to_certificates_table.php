<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            // Снимок ФИО и названия курса на момент выдачи. Если пусто —
            // CertificateService подставит живые значения из профиля/курса.
            $table->string('student_name')->nullable()->after('course_id');
            $table->string('course_title')->nullable()->after('student_name');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn(['student_name', 'course_title']);
        });
    }
};
