<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Возможные значения: super_admin, admin, teacher, manager. NULL = обычный студент.
            $table->string('role', 20)->nullable()->after('is_admin');
            $table->foreignId('teacher_id')
                ->nullable()
                ->after('role')
                ->constrained('teachers')
                ->nullOnDelete();

            $table->index('role');
        });

        // Backfill: всем существующим админам выдаём роль admin,
        // а основателю аккаунта — super_admin.
        DB::table('users')->where('is_admin', true)->update(['role' => 'admin']);
        DB::table('users')->where('email', 'pe4kinsmart@gmail.com')->update(['role' => 'super_admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
            $table->dropColumn(['role', 'teacher_id']);
        });
    }
};
