<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Псевдоним куратора, который видит студент в чате (пусто = ФИО).
            $table->string('curator_display_name')->nullable()->after('name');
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            // Кто из админов/кураторов отправил ответ (created_at = когда).
            $table->foreignId('answered_by')->nullable()->after('role')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('answered_by');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('curator_display_name');
        });
    }
};
