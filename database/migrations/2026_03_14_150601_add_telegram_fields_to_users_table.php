<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Если колонки telegram_id еще нет — создаем
            if (!Schema::hasColumn('users', 'telegram_id')) {
                $table->bigInteger('telegram_id')->nullable()->unique()->after('password');
            }
            
            // Если колонки telegram_auth_token еще нет — создаем
            if (!Schema::hasColumn('users', 'telegram_auth_token')) {
                $table->string('telegram_auth_token')->nullable()->after('telegram_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'telegram_auth_token')) {
                $table->dropColumn('telegram_auth_token');
            }
            if (Schema::hasColumn('users', 'telegram_id')) {
                $table->dropColumn('telegram_id');
            }
        });
    }
};