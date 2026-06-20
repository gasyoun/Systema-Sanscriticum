<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            // Режим техобслуживания студенческого кабинета (админка/вебхуки работают).
            $table->boolean('student_maintenance_enabled')->default(false);
            $table->text('student_maintenance_message')->nullable();
            // Токен обхода для секрет-ссылки (/maintenance-bypass/{token}).
            $table->string('student_maintenance_secret')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_settings', function (Blueprint $table) {
            $table->dropColumn([
                'student_maintenance_enabled',
                'student_maintenance_message',
                'student_maintenance_secret',
            ]);
        });
    }
};
